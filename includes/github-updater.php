<?php
/**
 * GitHub Updater — automatyczne aktualizacje wtyczki z GitHub
 *
 * Sprawdza najnowszy tag/release na GitHubie i wyświetla aktualizację
 * w WordPress Admin jak każda inna wtyczka.
 *
 * @package AI_Images
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AAI_GitHub_Updater {

    /**
     * @var string GitHub username/org
     */
    private $github_user = 'lukelocksmith';

    /**
     * @var string GitHub repo name
     */
    private $github_repo = 'agencyjnie-ai-images';

    /**
     * @var string Plugin basename (e.g. agencyjnie-ai-images/agencyjnie-ai-images.php)
     */
    private $plugin_basename;

    /**
     * @var string Current plugin version
     */
    private $current_version;

    /**
     * @var string Plugin slug
     */
    private $plugin_slug;

    /**
     * @var object|null Cached GitHub response
     */
    private $github_response = null;

    /**
     * Constructor
     */
    public function __construct() {
        $this->plugin_basename = AAI_PLUGIN_BASENAME;
        $this->plugin_slug     = dirname( $this->plugin_basename );
        $this->current_version = AAI_VERSION;

        // Hooks
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
        add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );

        // Czyść cache po sprawdzeniu aktualizacji ręcznie
        add_action( 'admin_init', array( $this, 'maybe_clear_cache' ) );
    }

    /**
     * Pobierz dane z GitHub API
     */
    private function get_github_data() {
        if ( null !== $this->github_response ) {
            return $this->github_response;
        }

        // Sprawdź cache (transient na 6h)
        $cached = get_transient( 'aai_github_update_data' );
        if ( false !== $cached ) {
            if ( 'no_data' === $cached || ! is_object( $cached ) || ! isset( $cached->tag_name ) ) {
                $this->github_response = false;
                return false;
            }
            $this->github_response = $cached;
            return $cached;
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            $this->github_user,
            $this->github_repo
        );

        $args = array(
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            ),
            'timeout' => 15,
        );

        // Dodaj token jeśli skonfigurowany (dla prywatnych repozytoriów)
        $token = aai_get_secure_option( 'github_token', '' );
        if ( ! empty( $token ) ) {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            // Fallback: spróbuj tags API
            $url = sprintf(
                'https://api.github.com/repos/%s/%s/tags',
                $this->github_user,
                $this->github_repo
            );

            $response = wp_remote_get( $url, $args );

            if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
                $this->github_response = false;
                set_transient( 'aai_github_update_data', 'no_data', HOUR_IN_SECONDS );
                return false;
            }

            $tags = json_decode( wp_remote_retrieve_body( $response ) );
            if ( empty( $tags ) || ! is_array( $tags ) ) {
                $this->github_response = false;
                set_transient( 'aai_github_update_data', 'no_data', HOUR_IN_SECONDS );
                return false;
            }

            // Użyj najnowszego taga
            $latest_tag = $tags[0];
            $data = (object) array(
                'tag_name'    => $latest_tag->name,
                'body'        => '',
                'published_at' => '',
                'zipball_url' => $latest_tag->zipball_url,
            );
        } else {
            $data = json_decode( wp_remote_retrieve_body( $response ) );
        }

        if ( empty( $data ) || ! isset( $data->tag_name ) ) {
            $this->github_response = false;
            set_transient( 'aai_github_update_data', 'no_data', HOUR_IN_SECONDS );
            return false;
        }

        $this->github_response = $data;
        set_transient( 'aai_github_update_data', $data, 6 * HOUR_IN_SECONDS );

        return $data;
    }

    /**
     * Normalizuj wersję (usuń prefix "v")
     */
    private function normalize_version( $version ) {
        return ltrim( $version, 'vV' );
    }

    /**
     * Sprawdź dostępność aktualizacji
     */
    public function check_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $github_data = $this->get_github_data();
        if ( false === $github_data ) {
            return $transient;
        }

        $remote_version = $this->normalize_version( $github_data->tag_name );

        if ( version_compare( $remote_version, $this->current_version, '>' ) ) {
            $download_url = $this->get_download_url( $github_data );

            $transient->response[ $this->plugin_basename ] = (object) array(
                'slug'        => $this->plugin_slug,
                'plugin'      => $this->plugin_basename,
                'new_version' => $remote_version,
                'url'         => sprintf( 'https://github.com/%s/%s', $this->github_user, $this->github_repo ),
                'package'     => $download_url,
                'icons'       => array(),
                'banners'     => array(),
                'tested'      => '',
                'requires'    => '5.8',
                'requires_php' => '7.4',
            );
        }

        return $transient;
    }

    /**
     * Pobierz URL do pobrania ZIP-a
     */
    private function get_download_url( $github_data ) {
        // Preferuj zipball_url (zawsze dostępny)
        $url = isset( $github_data->zipball_url ) ? $github_data->zipball_url : '';

        // Sprawdź czy release ma asset ZIP
        if ( ! empty( $github_data->assets ) && is_array( $github_data->assets ) ) {
            foreach ( $github_data->assets as $asset ) {
                if ( isset( $asset->browser_download_url ) && substr( $asset->name, -4 ) === '.zip' ) {
                    $url = $asset->browser_download_url;
                    break;
                }
            }
        }

        // Dodaj token do URL jeśli potrzebny (prywatne repo)
        $token = aai_get_secure_option( 'github_token', '' );
        if ( ! empty( $token ) && ! empty( $url ) ) {
            $url = add_query_arg( 'access_token', $token, $url );
        }

        return $url;
    }

    /**
     * Informacje o wtyczce (wyświetlane po kliknięciu "Zobacz szczegóły")
     */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        if ( ! isset( $args->slug ) || $args->slug !== $this->plugin_slug ) {
            return $result;
        }

        $github_data = $this->get_github_data();
        if ( false === $github_data ) {
            return $result;
        }

        $remote_version = $this->normalize_version( $github_data->tag_name );

        $plugin_info = (object) array(
            'name'          => 'AI Images',
            'slug'          => $this->plugin_slug,
            'version'       => $remote_version,
            'author'        => '<a href="https://important.is">important.is</a>',
            'homepage'      => sprintf( 'https://github.com/%s/%s', $this->github_user, $this->github_repo ),
            'short_description' => 'Automatyczne generowanie featured images przy użyciu AI (Gemini / DALL-E 3).',
            'sections'      => array(
                'description' => 'Automatyczne generowanie featured images przy użyciu AI (Gemini / DALL-E 3). Obsługuje masowe generowanie z listy wpisów.',
                'changelog'   => $this->format_changelog( $github_data ),
            ),
            'download_link' => $this->get_download_url( $github_data ),
            'requires'      => '5.8',
            'requires_php'  => '7.4',
            'tested'        => '',
            'last_updated'  => isset( $github_data->published_at ) ? $github_data->published_at : '',
        );

        return $plugin_info;
    }

    /**
     * Formatuj changelog z opisu release
     */
    private function format_changelog( $github_data ) {
        $body = isset( $github_data->body ) ? $github_data->body : '';

        if ( empty( $body ) ) {
            return '<p>Brak informacji o zmianach.</p>';
        }

        // Prosta konwersja markdown → HTML
        $html = nl2br( esc_html( $body ) );
        $html = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $html );
        $html = preg_replace( '/(<li>.*<\/li>)/s', '<ul>$1</ul>', $html );

        return $html;
    }

    /**
     * Po instalacji — popraw nazwę folderu
     *
     * GitHub ZIP-y mają format "user-repo-hash/", trzeba to zmienić na "agencyjnie-ai-images/"
     */
    public function after_install( $response, $hook_extra, $result ) {
        global $wp_filesystem;

        // Sprawdź czy to nasza wtyczka
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
            return $response;
        }

        $install_directory = plugin_dir_path( dirname( __FILE__ ) ) . $this->plugin_slug . '/';

        // Przenieś pliki z folderu GitHub do właściwego folderu wtyczki
        if ( isset( $result['destination'] ) && $result['destination'] !== $install_directory ) {
            $wp_filesystem->move( $result['destination'], $install_directory );
            $result['destination'] = $install_directory;
        }

        // Reaktywuj wtyczkę
        activate_plugin( $this->plugin_basename );

        return $response;
    }

    /**
     * Wyczyść cache jeśli użytkownik ręcznie sprawdza aktualizacje
     */
    public function maybe_clear_cache() {
        if ( isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) {
            delete_transient( 'aai_github_update_data' );
        }
    }
}

// Inicjalizacja
function aai_init_github_updater() {
    new AAI_GitHub_Updater();
}
add_action( 'admin_init', 'aai_init_github_updater' );
