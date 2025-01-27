<?php

namespace Midweste\GitPluginRepository;

/*
 * Plugin Name:       WordPress Github/Bitbucket Plugin Updater as mu-plugin
 * Plugin URI:        https://github.org/midweste/wp-git-plugin-repository
 * Description:       Use a Github/Bitbucket public repo api as a plugin Update URI: repository for WordPress plugin updates. Works with WordPress automatic updates.
 * Author:            Midweste
 * Author URI:        https://github.org/midweste/wp-git-plugin-repository
 * License:           MIT
 */

call_user_func(function () {
    function install_as_mu(array $files)
    {
        $plugin_files = [
            'wp-git-plugin-repository/wp-git-plugin-repository.php'
        ];

        foreach ($plugin_files as $plugin_file) {
            $plugin_file_abs = trailingslashit(WP_PLUGIN_DIR) . $plugin_file;
            if (!file_exists($plugin_file_abs)) {
                continue;
            }

            require $plugin_file_abs;

            // remove plugin action links
            add_filter('network_admin_plugin_action_links_' . $plugin_file, function ($actions) {
                unset($actions['activate'], $actions['delete'], $actions['deactivate']);
                return $actions;
            });
            add_filter('plugin_action_links_' . $plugin_file, function ($actions) {
                unset($actions['activate'], $actions['delete'], $actions['deactivate']);
                return $actions;
            });

            // show as active and disable checkbox
            add_action('after_plugin_row_' . $plugin_file, function ($plugin_file) {
                $html = <<<HTML
                <script>jQuery('.inactive[data-plugin="{$plugin_file}"]').attr('class','active');</script>
                <script>jQuery('.active[data-plugin="{$plugin_file}"] .check-column input').attr( 'disabled','disabled' );</script>
            HTML;
                echo $html;
            });

            // show as mu-plugin
            add_action('after_plugin_row_meta', function ($plugin_file) use ($plugin_files) {
                if (!in_array($plugin_file, (array) $plugin_files, true)) {
                    return;
                }
                printf('<br>%s', esc_html__('Activated as a mu-plugin', 'wp-git-plugin-repository'));
            });
        }

        // mark as active plugin
        add_filter('option_active_plugins', function ($active_plugins) use ($plugin_files) {
            return array_unique(array_merge($active_plugins, $plugin_files));
        }, PHP_INT_MIN, 1);
    }


    $plugin_files = [
        'wp-git-plugin-repository/wp-git-plugin-repository.php'
    ];

    foreach ($plugin_files as $plugin_file) {
        $plugin_file_abs = trailingslashit(WP_PLUGIN_DIR) . $plugin_file;
        if (!file_exists($plugin_file_abs)) {
            continue;
        }

        require $plugin_file_abs;

        // remove plugin action links
        add_filter('network_admin_plugin_action_links_' . $plugin_file, function ($actions) {
            unset($actions['activate'], $actions['delete'], $actions['deactivate']);
            return $actions;
        });
        add_filter('plugin_action_links_' . $plugin_file, function ($actions) {
            unset($actions['activate'], $actions['delete'], $actions['deactivate']);
            return $actions;
        });

        // show as active and disable checkbox
        add_action('after_plugin_row_' . $plugin_file, function ($plugin_file) {
            $html = <<<HTML
                <script>jQuery('.inactive[data-plugin="{$plugin_file}"]').attr('class','active');</script>
                <script>jQuery('.active[data-plugin="{$plugin_file}"] .check-column input').attr( 'disabled','disabled' );</script>
            HTML;
            echo $html;
        });

        // show as mu-plugin
        add_action('after_plugin_row_meta', function ($plugin_file) use ($plugin_files) {
            if (!in_array($plugin_file, (array) $plugin_files, true)) {
                return;
            }
            printf('<br>%s', esc_html__('Activated as a mu-plugin', 'wp-git-plugin-repository'));
        });
    }

    // mark as active plugin
    add_filter('option_active_plugins', function ($active_plugins) use ($plugin_files) {
        return array_unique(array_merge($active_plugins, $plugin_files));
    }, PHP_INT_MIN, 1);
});
