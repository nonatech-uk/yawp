<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class YAWP_Updater {

    private $plugin_slug = 'yawp/yawp.php';
    private $cache_key   = 'yawp_github_release';
    private $cache_ttl   = 3600; // 1 hour

    public function __construct() {
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );
        add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
        add_filter( 'upgrader_post_install', [ $this, 'post_install' ], 10, 3 );
    }

    /**
     * Check GitHub for a newer release and inject into the update transient.
     */
    public function check_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        // Clear our cache so we always get the latest from GitHub.
        delete_transient( $this->cache_key );
        $release = $this->get_latest_release();
        if ( ! $release ) {
            return $transient;
        }

        $remote_version = ltrim( $release['tag_name'], 'v' );

        if ( version_compare( $remote_version, YAWP_VERSION, '>' ) ) {
            $transient->response[ $this->plugin_slug ] = (object) [
                'slug'        => 'yawp',
                'plugin'      => $this->plugin_slug,
                'new_version' => $remote_version,
                'url'         => 'https://github.com/' . YAWP_GITHUB_REPO,
                'package'     => $release['zipball_url'],
            ];
        }

        return $transient;
    }

    /**
     * Provide plugin info for the update details modal.
     */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || ! isset( $args->slug ) || 'yawp' !== $args->slug ) {
            return $result;
        }

        $release = $this->get_latest_release();
        if ( ! $release ) {
            return $result;
        }

        return (object) [
            'name'          => 'YAWP',
            'slug'          => 'yawp',
            'version'       => ltrim( $release['tag_name'], 'v' ),
            'author'        => 'Nonatech UK',
            'homepage'      => 'https://github.com/' . YAWP_GITHUB_REPO,
            'download_link' => $release['zipball_url'],
            'sections'      => [
                'description' => 'Yet Another WordPress (Backup) Plugin — incremental S3 backups with Object Lock.',
                'changelog'   => nl2br( esc_html( $release['body'] ?? '' ) ),
            ],
        ];
    }

    /**
     * After install, rename the extracted directory to match the expected plugin slug.
     */
    public function post_install( $response, $hook_extra, $result ) {
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_slug ) {
            return $result;
        }

        global $wp_filesystem;

        $proper_dir = WP_PLUGIN_DIR . '/yawp/';
        if ( $result['destination'] !== $proper_dir ) {
            $wp_filesystem->move( $result['destination'], $proper_dir );
            $result['destination']      = $proper_dir;
            $result['destination_name'] = 'yawp';
        }

        activate_plugin( $this->plugin_slug );

        return $result;
    }

    /**
     * Fetch latest release from GitHub (cached).
     */
    private function get_latest_release() {
        $cached = get_transient( $this->cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $url = 'https://api.github.com/repos/' . YAWP_GITHUB_REPO . '/releases/latest';
        $response = wp_remote_get( $url, [
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'YAWP/' . YAWP_VERSION,
            ],
            'timeout' => 10,
        ]);

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['tag_name'] ) || empty( $body['zipball_url'] ) ) {
            return false;
        }

        $data = [
            'tag_name'    => $body['tag_name'],
            'zipball_url' => $body['zipball_url'],
            'body'        => $body['body'] ?? '',
        ];

        set_transient( $this->cache_key, $data, $this->cache_ttl );
        return $data;
    }
}
