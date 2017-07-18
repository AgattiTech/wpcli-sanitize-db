<?php

if ( ! defined( 'WP_CLI' ) ) {
    return;
}


class Sanitize_DB extends WP_CLI_Command {


    public function __construct() {
        if ( file_exists( 'vendor/autoload.php' ) ) {
            // needed when runing via git clone; not needed when running as a wp-cli package
            require 'vendor/autoload.php';
        }

        $this->faker = Faker\Factory::create();
    }


    /**
     * Sanitizes all sensitive data in a database.
     *
     * Runs all other available `wp sanitize` commands.
     *
     * ## EXAMPLES
     *
     *     wp sanitize db
     *
     */
    public function db( $args, $assoc_args ) {

        WP_CLI::confirm( "Are you sure you want to DELETE this sensitive data in the database?", $assoc_args );

        // skip future prompts
        $assoc_args['yes'] = true;

        $this->transients($args, $assoc_args);
        $this->comments($args, $assoc_args);
        $this->users($args, $assoc_args);

        //$active_plugins = (array) get_option( 'active_plugins', array() );
        $active_plugins = get_plugins();
        if (in_array( 'gravityforms/gravityforms.php', $active_plugins ) || array_key_exists( 'gravityforms/gravityforms.php', $active_plugins )) {
            $this->gravityforms($args, $assoc_args);
        }
        if (in_array( 'woocommerce/woocommerce.php', $active_plugins ) || array_key_exists( 'woocommerce/woocommerce.php', $active_plugins )) {
            $this->woocommerce($args, $assoc_args);
        }

        WP_CLI::success( "Database sanitized." );
    }


    /**
     * Sanitizes sensitive user data in a database.
     *
     * ## EXAMPLES
     *
     *     wp sanitize users
     *
     */
    public function users( $args, $assoc_args ) {

        WP_CLI::confirm( "Are you sure you want to DELETE this sensitive data in the database?", $assoc_args );
        WP_CLI::log(WP_CLI::colorize('%cSanitizing user data%n'));

        // wp_update_user is too slow
        // changing a users email or passwor via wp_update_user will send them an email; let's not do that

        global $wpdb;

        $users = get_users();
        $count = 0;
        $start = time();
        foreach ($users as $user) {
            $count += 1;
            if (!($count % 1000)) {
                $end = time();
                $elapsed = $end - $start;
                $start = $end;
                WP_CLI::log('Processed ' . $count . ' users in ' . $elapsed . ' seconds');
            }
            if (false !== strpos($user->user_email, '@freshconsulting.com')) {
                // skip Fresh Consulting users
                continue;
            }
            $username = $this->faker->userName;
            $first_name = $this->faker->firstName;
            $last_name = $this->faker->lastName;

            $usermetadata = [
                'first_name' => $first_name,
                'last_name' => $last_name,
                'nickname' => $this->faker->domainWord,
                'description' => $this->faker->text($maxNbChars = 200),
            ];
            foreach ($usermetadata as $key => $value) {
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$wpdb->usermeta} SET `meta_value` = %s WHERE `user_id` = %d AND `meta_key` = %s",
                        array($value, $user->ID, $key)
                    )
                );
            }

            // need a query to update user_login
            $userdata = [
                'user_pass' => $this->faker->password,
                'user_login' => $username,
                'user_email' => $this->faker->safeEmail,
                'user_nicename' => $username,
                'display_name' => $first_name . ' ' . $last_name,
            ];
            if ($user->user_url) {
                $userdata['user_url'] = $this->faker->domainName;
            }
            $wpdb->update($wpdb->users, $userdata, array('ID' => $user->ID));

        }

        $delete_keys = [
            'facebook',
            'googleplus',
            'jabber',
            'aim',
            'yim',
        ];

        foreach ($delete_keys as $meta_key) {
            WP_CLI::log('Starting ' . $meta_key);
            $umeta_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->usermeta} WHERE (`meta_key` = %s OR `meta_key` = %s)",
                    array($meta_key, '_' . $meta_key)
                )
            );
        }

    }


    /**
     * Sanitizes sensitive comments data in a database.
     *
     * ## EXAMPLES
     *
     *     wp sanitize comments
     *
     */
    public function comments( $args, $assoc_args ) {

        WP_CLI::confirm( "Are you sure you want to DELETE this sensitive data in the database?", $assoc_args );
        WP_CLI::log(WP_CLI::colorize('%cSanitizing non-public comments%n'));

        // Comments
        // public comments are public but unapproved comments are not
        $comments = get_comments(['status' => 'hold']);
        foreach ($comments as $comment) {
            if ('pingback' === $comment->comment_type) {
                continue;
            }
            $commentarr = [
                'comment_ID' => $comment->comment_ID,
                'comment_author' => $this->faker->name,
                'comment_author_email' => $this->faker->safeEmail,
                'comment_author_url' => $this->faker->domainName,
                'comment_content' => $this->faker->text($maxNbChars = 400),
            ];
            $result = wp_update_comment($commentarr);
        }
    }


    /**
     * Sanitizes sensitive gravityforms data in a database.
     *
     * ## EXAMPLES
     *
     *     wp sanitize gravityforms
     *
     */
    public function gravityforms( $args, $assoc_args ) {

        WP_CLI::confirm( "Are you sure you want to DELETE this sensitive data in the database?", $assoc_args );
        WP_CLI::log(WP_CLI::colorize('%cSanitizing Gravity Forms tables%n'));

        // we don't know what is in here, it's not used at runtime, so delete everything

        /* going one by one maxes out my 16GB of memory
         * we have sites with 6.9 million+ rows in wp_rg_lead_detail
        $forms = GFAPI::get_forms();
        foreach ($forms as $form) {
            $entry_count = GFAPI::count_entries($form['id']);
            $entries = GFAPI::get_entries($form['id'], array(), null, array('offset' => 0, 'page_size' => $entry_count));
            foreach ($entries as $entry) {
                GFAPI::delete_entry($entry['id']);
            }
        }
         */
        global $wpdb;

        $gf_tables = [
            'wp_rg_incomplete_submissions',
            'wp_rg_lead',
            'wp_rg_lead_detail',
            'wp_rg_lead_detail_long',
            'wp_rg_lead_meta',
            'wp_rg_lead_notes',
        ];
        foreach ($gf_tables as $table) {
            $wpdb->query(
                "TRUNCATE " . $table
            );
        }
    }


    /**
     * Sanitizes sensitive woocommerce data in a database.
     *
     * ## EXAMPLES
     *
     *     wp sanitize woocommerce
     *
     */
    public function woocommerce( $args, $assoc_args ) {

        WP_CLI::confirm( "Are you sure you want to DELETE this sensitive data in the database?", $assoc_args );
        WP_CLI::log(WP_CLI::colorize('%cSanitizing WooCommerce data%n'));

        // calling `update_user_meta()` for each field for each user (or order) is too slow; it takes 40 seconds/100 users (or orders).

        global $wpdb;
        // these fields may appear in both the usermeta and postmeta tables both with and without a leading underscore (_)
        $user_fields = [
            'billing_first_name' => 'firstName',
            'billing_last_name' => 'lastName',
            'billing_company' => 'company',
            'billing_email' => 'safeEmail',
            'billing_phone' => 'phoneNumber',
            'billing_country' => 'countryCode',
            'billing_address_1' => 'streetAddress',
            'billing_address_2' => 'secondaryAddress',
            'billing_city' => 'city',
            'billing_state' => 'stateAbbr',
            'billing_postcode' => 'postcode',
            'shipping_first_name' => 'firstName',
            'shipping_last_name' => 'lastName',
            'shipping_full_name' => 'name',
            'shipping_company' => 'company',
            'shipping_phone' => 'phoneNumber',
            'shipping_country' => 'countryCode',
            'shipping_address_1' => 'streetAddress',
            'shipping_address_2' => 'secondaryAddress',
            'shipping_city' => 'city',
            'shipping_state' => 'stateAbbr',
            'shipping_postcode' => 'postcode',
            'credit_card_holder_name' => 'name',
            'cc_last_4' => null,
        ];


        foreach ($user_fields as $meta_key => $faker_function) {
            WP_CLI::log('Starting ' . $meta_key);
            $umeta_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT umeta_id FROM {$wpdb->usermeta} WHERE (`meta_key` = %s OR `meta_key` = %s) AND `meta_value` IS NOT NULL AND `meta_value` != ''",
                    array($meta_key, '_' . $meta_key)
                )
            );
            foreach ($umeta_ids as $umeta_id) {
                $new_value = null;
                if ($faker_function) {
                    $new_value = $this->faker->$faker_function;
                } else if ($meta_key === 'cc_last_4') {
                    $new_Value = $this->faker->randomNumber(4);
                }
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$wpdb->usermeta} SET `meta_value` = %s WHERE `umeta_id` = %d",
                        array($new_value, $umeta_id)
                    )
                );
            }
            $meta_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT meta_id FROM {$wpdb->postmeta} WHERE (`meta_key` = %s OR `meta_key` = %s) AND `meta_value` IS NOT NULL AND `meta_value` != ''",
                    array($meta_key, '_' . $meta_key)
                )
            );
            foreach ($meta_ids as $meta_id) {
                $new_value = null;
                if ($faker_function) {
                    $new_value = $this->faker->$faker_function;
                } else if ($meta_key === 'cc_last_4') {
                    $new_Value = $this->faker->randomNumber(4);
                }
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$wpdb->postmeta} SET `meta_value` = %s WHERE `meta_id` = %d",
                        array($new_value, $meta_id)
                    )
                );
            }
        }

    }


    // from wpcli Transient_Command::delete_all()
    /**
     * Deletes transients in a database.
     *
     * ## EXAMPLES
     *
     *     wp sanitize transients
     *
     */
    public function transients( $args, $assoc_args ) {

        WP_CLI::confirm( "Are you sure you want to DELETE this sensitive data in the database?", $assoc_args );
        WP_CLI::log(WP_CLI::colorize('%cSanitizing transients%n'));
        WP_CLI::runcommand('transient delete --all');

    }

}
WP_CLI::add_command('sanitize', 'Sanitize_DB');

