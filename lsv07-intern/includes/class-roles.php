<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Roles {

    public static function init() {
        // Neue Rollen hinzufügen falls noch nicht vorhanden
        if ( ! get_role( LSV07I_ROLE_INTERN ) ) {
            self::create_roles();
        }
        // Triathlon-Rollen separat prüfen (nachrüsten bei bestehenden Installs)
        if ( ! get_role( LSV07I_ROLE_TRIATHLONWART ) ) {
            self::create_triathlon_roles();
        }
        // Fitness-Rollen separat prüfen (nachrüsten bei bestehenden Installs)
        if ( ! get_role( LSV07I_ROLE_FITNESSWART ) ) {
            self::create_fitness_roles();
        }
    }

    public static function create_roles() {
        add_role( LSV07I_ROLE_INTERN, 'Intern', [
            'read'          => true,
            'lsv07i_intern' => true,
        ] );
        add_role( LSV07I_ROLE_FINANZWART, 'Finanzwart', [
            'read'              => true,
            'lsv07i_intern'     => true,
            'lsv07i_finanzwart' => true,
        ] );
        add_role( LSV07I_ROLE_SCHWIMMWART, 'Schwimmwart', [
            'read'               => true,
            'lsv07i_intern'      => true,
            'lsv07i_schwimmwart' => true,
        ] );
        self::create_triathlon_roles();
        self::create_fitness_roles();
    }

    public static function create_triathlon_roles() {
        add_role( LSV07I_ROLE_TRIATHLONWART, 'Triathlonwart', [
            'read'                   => true,
            'lsv07i_tri_intern'      => true,
            'lsv07i_triathlonwart'   => true,
        ] );
        add_role( LSV07I_ROLE_TRI_INTERN, 'Triathlon Intern', [
            'read'              => true,
            'lsv07i_tri_intern' => true,
        ] );
    }

    public static function create_fitness_roles() {
        add_role( LSV07I_ROLE_FITNESSWART, 'Fitnesswart', [
            'read'                => true,
            'lsv07i_fit_intern'   => true,
            'lsv07i_fitnesswart'  => true,
        ] );
        add_role( LSV07I_ROLE_FIT_INTERN, 'Fitness Intern', [
            'read'              => true,
            'lsv07i_fit_intern' => true,
        ] );
    }

    public static function remove_roles() {
        remove_role( LSV07I_ROLE_INTERN );
        remove_role( LSV07I_ROLE_FINANZWART );
        remove_role( LSV07I_ROLE_SCHWIMMWART );
        remove_role( LSV07I_ROLE_TRIATHLONWART );
        remove_role( LSV07I_ROLE_TRI_INTERN );
        remove_role( LSV07I_ROLE_FITNESSWART );
        remove_role( LSV07I_ROLE_FIT_INTERN );
    }
}
