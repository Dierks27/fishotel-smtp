<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FHSMTP_Updater {

    private $repo;
    private $plugin_file;
    private $plugin_slug;
    private $version;
    private $cache_key = 'fhsmtp_update_check';
    private $cache_ttl = 43200; // 12 hours

    /**
     * @param string $repo         GitHub repo in "owner/repo" format.
     * @param string $plugin_file  Full path to main plugin file.
     * @param string $version      Current plugin version.
     */
    public function __construct( $repo, $plugin_file, $version ) {
        $this->repo        = $repo;
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename( $plugin_file );
        $this->version     = $version;

        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
        add_filter( 'upgrader_process_complete', array( $this, 'after_update' ), 10, 2 );
    }

    /**
     * Fetch latest version data from GitHub tags, with caching.
     */
    private function get_release_data() {
        $cached = get_transient( $this->cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $url = sprintf( 'https://api.github.com/repos/%s/tags?per_page=1', $this->repo );

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'FisHotel-SMTP-Updater/' . $this->version,
            ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return false;
        }

        $tags = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $tags ) || empty( $tags[0]['name'] ) ) {
            return false;
        }

        $tag = $tags[0];
        $version = ltrim( $tag['name'], 'v' );
        $download_url = sprintf(
            'https://api.github.com/repos/%s/zipball/%s',
            $this->repo,
            $tag['name']
        );
        $html_url = sprintf(
            'https://github.com/%s/releases/tag/%s',
            $this->repo,
            $tag['name']
        );

        $data = array(
            'version'      => $version,
            'download_url' => $download_url,
            'description'  => '',
            'published_at' => '',
            'html_url'     => $html_url,
        );

        set_transient( $this->cache_key, $data, $this->cache_ttl );
        return $data;
    }

    /**
     * Inject update info into the WP update transient if a newer version exists.
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_release_data();
        if ( ! $release || empty( $release['version'] ) || empty( $release['download_url'] ) ) {
            return $transient;
        }

        if ( version_compare( $release['version'], $this->version, '>' ) ) {
            $transient->response[ $this->plugin_slug ] = (object) array(
                'slug'        => dirname( $this->plugin_slug ),
                'plugin'      => $this->plugin_slug,
                'new_version' => $release['version'],
                'url'         => $release['html_url'],
                'package'     => $release['download_url'],
            );
        }

        return $transient;
    }

    /**
     * Provide plugin info for the WP plugin details modal.
     */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        if ( ! isset( $args->slug ) || dirname( $this->plugin_slug ) !== $args->slug ) {
            return $result;
        }

        $release = $this->get_release_data();
        if ( ! $release ) {
            return $result;
        }

        $plugin_data = get_plugin_data( $this->plugin_file );

        return (object) array(
            'name'          => $plugin_data['Name'],
            'slug'          => dirname( $this->plugin_slug ),
            'version'       => $release['version'],
            'author'        => $plugin_data['Author'],
            'homepage'      => $plugin_data['PluginURI'],
            'download_link' => $release['download_url'],
            'requires'      => '5.0',
            'tested'        => '6.7',
            'requires_php'  => '7.4',
            'last_updated'  => $release['published_at'],
            'sections'      => array(
                'description' => $plugin_data['Description'],
                'changelog'   => nl2br( esc_html( $release['description'] ) ),
            ),
        );
    }

    /**
     * Clear the update cache after an upgrade completes.
     */
    public function after_update( $upgrader, $options ) {
        if ( 'update' === ( $options['action'] ?? '' ) && 'plugin' === ( $options['type'] ?? '' ) ) {
            $plugins = $options['plugins'] ?? array();
            if ( in_array( $this->plugin_slug, $plugins, true ) ) {
                delete_transient( $this->cache_key );
            }
        }
    }
}
