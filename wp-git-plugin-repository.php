<?php

namespace Midweste\GitPluginRepository;

/*
 * Plugin Name:       WordPress Github/Bitbucket Plugin Updater
 * Version: 2023.08.31.16.52.40
 * Plugin URI:        https://github.org/midweste/wp-git-plugin-repository
 * Description:       Use a Github/Bitbucket public repo api as a plugin Update URI: repository for WordPress plugin updates. Works with WordPress automatic updates.
 * Author:            Midweste
 * Author URI:        https://github.org/midweste/wp-git-plugin-repository
 * Update URI:        https://api.github.com/repos/midweste/wp-git-plugin-repository/commits/main
 * License:           MIT
 */

call_user_func(function () {
    foreach (glob(__DIR__ . '/src/*.php') as $file) {
        require_once $file;
    }
    foreach (glob(__DIR__ . '/src/Plugins/*.php') as $file) {
        require_once $file;
    }
});

// apply_filters( 'plugin_row_meta', $plugin_meta, $plugin_file, $plugin_data, $status );
add_filter('plugin_row_meta', function ($plugin_meta, $plugin_file, $plugin_data, $status) {
    if ($status !== 'mustuse') {
        return $plugin_meta;
    }

    if (empty($plugin_data['UpdateURI']) || !filter_var($plugin_data['UpdateURI'], FILTER_VALIDATE_URL)) {
        return $plugin_meta;
    }

    $mu_updater = UpdaterBase::getUpdater($plugin_file);
    if (is_null($mu_updater)) {
        return $plugin_meta;
    }

    // check for new version
    $updater = new $mu_updater($plugin_file);
    $new_version = $updater->getLatestVersion();
    if (empty($new_version) || !version_compare($new_version, $plugin_data['Version'], '>')) {
        return $plugin_meta;
    }

    // add update link
    $update_url = get_permalink();
    $update_url = add_query_arg('action', 'mu-update', $update_url);
    $update_url = add_query_arg('file', $plugin_file, $update_url);
    $update_url = add_query_arg('nonce', wp_create_nonce(__FILE__), $update_url);

    $update_link = sprintf('<strong><a href="%s">Update to %s</a></strong>', $update_url, $new_version);
    $plugin_meta[] = $update_link;

    return $plugin_meta;
}, 10, 4);

add_action('admin_init', function () {
    if (
        isset($_GET['action']) && $_GET['action'] === 'mu-update'
        && current_user_can('manage_options')
        && wp_verify_nonce($_GET['nonce'], __FILE__)
        && isset($_GET['file'])
    ) {
        $updated = Helpers::mu_update($_GET['file']);

        $update_url = get_permalink();
        $update_url = remove_query_arg('action', $update_url);
        $update_url = remove_query_arg('file', $update_url);
        $update_url = remove_query_arg('nonce', $update_url);
        wp_safe_redirect($update_url);
        exit;
    }

    // for testing, reset update cache
    // wp_clean_update_cache();

    foreach (UpdaterBase::getImplementations() as $updater) {
        $hook = 'update_plugins_' . $updater::getUpdateHost();
        add_filter($hook, function ($wp_update, $plugin_data, $plugin_file, $locales) use ($updater) {
            try {
                $updater = new $updater($plugin_file);
                return $updater->update($locales);
            } catch (\Throwable $e) {
                error_log(sprintf('Could not get updates for %s %s. %s', $plugin_file, $plugin_data['UpdateURI'], $e->getMessage()));
            }
        }, 20, 4);
    }
});
