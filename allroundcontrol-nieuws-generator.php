<?php
/**
 * Plugin Name:       AllroundControl Nieuws Generator
 * Plugin URI:        https://allroundcontrol.nl
 * Description:       Genereert dagelijks automatisch nieuwsberichten over de diensten van AllroundControl via de Claude AI API. Inclusief automatische uitgelichte afbeelding via Pexels.
 * Version:           1.2.0
 * Author:            AllroundControl
 * Author URI:        https://allroundcontrol.nl
 * License:           GPL-2.0+
 * Text Domain:       arc-nieuws
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ARC_NIEUWS_VERSION',     '1.1.0' );
define( 'ARC_NIEUWS_OPTION_KEY',  'arc_nieuws_settings' );
define( 'ARC_NIEUWS_CRON_HOOK',   'arc_nieuws_dagelijks_bericht' );
define( 'ARC_NIEUWS_PLUGIN_SLUG', 'allroundcontrol-nieuws-generator/allroundcontrol-nieuws-generator.php' );

// ─────────────────────────────────────────────
// Activatie / deactivatie
// ─────────────────────────────────────────────

register_activation_hook( __FILE__, 'arc_nieuws_activeer' );
register_deactivation_hook( __FILE__, 'arc_nieuws_deactiveer' );

function arc_nieuws_activeer() {
    if ( ! wp_next_scheduled( ARC_NIEUWS_CRON_HOOK ) ) {
        $settings  = arc_nieuws_get_settings();
        $timestamp = arc_nieuws_volgende_tijd( $settings['uur'] );
        wp_schedule_event( $timestamp, 'daily', ARC_NIEUWS_CRON_HOOK );
    }
}

function arc_nieuws_deactiveer() {
    $timestamp = wp_next_scheduled( ARC_NIEUWS_CRON_HOOK );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, ARC_NIEUWS_CRON_HOOK );
    }
}

// ─────────────────────────────────────────────
// Instellingen ophalen
// ─────────────────────────────────────────────

function arc_nieuws_get_settings() {
    $defaults = array(
        'api_key'         => '',
        'pexels_api_key'  => '',
        'github_repo'     => '',
        'model'           => 'claude-haiku-4-5',
        'auteur_id'       => 1,
        'categorie_id'    => 0,
        'post_status'     => 'publish',
        'uur'             => 8,
        'ingeschakeld'    => 1,
        'onderwerp_index' => 0,
    );
    $opgeslagen = get_option( ARC_NIEUWS_OPTION_KEY, array() );
    return wp_parse_args( $opgeslagen, $defaults );
}

// ─────────────────────────────────────────────
// Admin menu
// ─────────────────────────────────────────────

add_action( 'admin_menu', 'arc_nieuws_admin_menu' );

function arc_nieuws_admin_menu() {
    add_options_page(
        'AllroundControl Nieuws Generator',
        'Nieuws Generator',
        'manage_options',
        'arc-nieuws-generator',
        'arc_nieuws_instellingen_pagina'
    );
}

// ─────────────────────────────────────────────
// Instellingenpagina
// ─────────────────────────────────────────────

function arc_nieuws_instellingen_pagina() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $bericht = '';

    // Instellingen opslaan
    if ( isset( $_POST['arc_nieuws_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['arc_nieuws_nonce'] ) ), 'arc_nieuws_opslaan' ) ) {
        $settings = arc_nieuws_get_settings();

        $settings['api_key']        = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
        $settings['pexels_api_key'] = sanitize_text_field( wp_unslash( $_POST['pexels_api_key'] ?? '' ) );
        $settings['github_repo']    = sanitize_text_field( wp_unslash( $_POST['github_repo'] ?? '' ) );
        $settings['model']          = sanitize_text_field( wp_unslash( $_POST['model'] ?? 'claude-haiku-4-5' ) );
        $settings['auteur_id']      = absint( $_POST['auteur_id'] ?? 1 );
        $settings['categorie_id']   = absint( $_POST['categorie_id'] ?? 0 );
        $settings['post_status']    = in_array( $_POST['post_status'] ?? '', array( 'publish', 'draft' ), true )
            ? sanitize_text_field( wp_unslash( $_POST['post_status'] ) )
            : 'publish';
        $settings['uur']            = intval( $_POST['uur'] ?? 8 );
        $settings['ingeschakeld']   = isset( $_POST['ingeschakeld'] ) ? 1 : 0;

        update_option( ARC_NIEUWS_OPTION_KEY, $settings );

        // Cron opnieuw plannen
        $bestaand = wp_next_scheduled( ARC_NIEUWS_CRON_HOOK );
        if ( $bestaand ) {
            wp_unschedule_event( $bestaand, ARC_NIEUWS_CRON_HOOK );
        }
        if ( $settings['ingeschakeld'] ) {
            $timestamp = arc_nieuws_volgende_tijd( $settings['uur'] );
            wp_schedule_event( $timestamp, 'daily', ARC_NIEUWS_CRON_HOOK );
        }

        $bericht = '<div class="notice notice-success"><p>Instellingen opgeslagen.</p></div>';
    }

    // Handmatig genereren
    if ( isset( $_POST['arc_nieuws_genereer_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['arc_nieuws_genereer_nonce'] ) ), 'arc_nieuws_genereer' ) ) {
        $resultaat = arc_nieuws_genereer_bericht();
        if ( is_wp_error( $resultaat ) ) {
            $bericht = '<div class="notice notice-error"><p>Fout: ' . esc_html( $resultaat->get_error_message() ) . '</p></div>';
        } else {
            $url     = get_edit_post_link( $resultaat );
            $bericht = '<div class="notice notice-success"><p>Bericht aangemaakt! <a href="' . esc_url( $url ) . '">Bekijk bericht &rarr;</a></p></div>';
        }
    }

    $settings    = arc_nieuws_get_settings();
    $volgende    = wp_next_scheduled( ARC_NIEUWS_CRON_HOOK );
    $gebruikers  = get_users( array( 'role__in' => array( 'administrator', 'editor', 'author' ) ) );
    $categorieen = get_categories( array( 'hide_empty' => false ) );
    $modellen    = array(
        'claude-haiku-4-5'   => 'Claude Haiku 4.5 (snel &amp; voordelig)',
        'claude-sonnet-4-6'  => 'Claude Sonnet 4.6 (uitgebreid)',
        'claude-opus-4-6'    => 'Claude Opus 4.6 (beste kwaliteit)',
    );
    ?>
    <div class="wrap">
        <h1>AllroundControl Nieuws Generator</h1>
        <?php echo wp_kses_post( $bericht ); ?>

        <div style="display:grid;grid-template-columns:1fr 340px;gap:24px;margin-top:16px;">

            <!-- Instellingenformulier -->
            <div>
                <form method="post">
                    <?php wp_nonce_field( 'arc_nieuws_opslaan', 'arc_nieuws_nonce' ); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="api_key">Anthropic API-sleutel</label></th>
                            <td>
                                <input type="password" id="api_key" name="api_key"
                                    value="<?php echo esc_attr( $settings['api_key'] ); ?>"
                                    class="regular-text" autocomplete="off" />
                                <p class="description">Haal je sleutel op via <a href="https://console.anthropic.com/" target="_blank" rel="noopener">console.anthropic.com</a></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="pexels_api_key">Pexels API-sleutel <span style="font-weight:400;">(afbeeldingen)</span></label></th>
                            <td>
                                <input type="password" id="pexels_api_key" name="pexels_api_key"
                                    value="<?php echo esc_attr( $settings['pexels_api_key'] ); ?>"
                                    class="regular-text" autocomplete="off" />
                                <p class="description">
                                    Gratis aan te maken via <a href="https://www.pexels.com/api/" target="_blank" rel="noopener">pexels.com/api</a>.
                                    Zonder sleutel wordt er geen uitgelichte afbeelding toegevoegd.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="github_repo">GitHub repository</label></th>
                            <td>
                                <input type="text" id="github_repo" name="github_repo"
                                    value="<?php echo esc_attr( $settings['github_repo'] ); ?>"
                                    class="regular-text" placeholder="gebruikersnaam/allroundcontrol-nieuws-generator" />
                                <p class="description">
                                    Vul in als <code>gebruikersnaam/repository-naam</code>. Zodra je een nieuwe versie op GitHub plaatst verschijnt de update automatisch in WordPress.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="model">AI-model</label></th>
                            <td>
                                <select id="model" name="model">
                                    <?php foreach ( $modellen as $waarde => $label ) : ?>
                                        <option value="<?php echo esc_attr( $waarde ); ?>" <?php selected( $settings['model'], $waarde ); ?>>
                                            <?php echo wp_kses_post( $label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="auteur_id">Auteur</label></th>
                            <td>
                                <select id="auteur_id" name="auteur_id">
                                    <?php foreach ( $gebruikers as $gebruiker ) : ?>
                                        <option value="<?php echo esc_attr( $gebruiker->ID ); ?>" <?php selected( $settings['auteur_id'], $gebruiker->ID ); ?>>
                                            <?php echo esc_html( $gebruiker->display_name ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="categorie_id">Categorie</label></th>
                            <td>
                                <select id="categorie_id" name="categorie_id">
                                    <option value="0">— Geen categorie —</option>
                                    <?php foreach ( $categorieen as $cat ) : ?>
                                        <option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( $settings['categorie_id'], $cat->term_id ); ?>>
                                            <?php echo esc_html( $cat->name ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="post_status">Status bericht</label></th>
                            <td>
                                <select id="post_status" name="post_status">
                                    <option value="publish" <?php selected( $settings['post_status'], 'publish' ); ?>>Publiceren</option>
                                    <option value="draft" <?php selected( $settings['post_status'], 'draft' ); ?>>Concept (handmatig controleren)</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="uur">Publicatietijdstip</label></th>
                            <td>
                                <select id="uur" name="uur">
                                    <?php for ( $h = 0; $h < 24; $h++ ) : ?>
                                        <option value="<?php echo esc_attr( $h ); ?>" <?php selected( $settings['uur'], $h ); ?>>
                                            <?php echo esc_html( sprintf( '%02d:00', $h ) ); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <p class="description">Servertijd (UTC). Huidige servertijd: <?php echo esc_html( gmdate( 'H:i' ) ); ?> UTC</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Inschakelen</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ingeschakeld" value="1" <?php checked( $settings['ingeschakeld'], 1 ); ?> />
                                    Dagelijks automatisch een bericht genereren
                                </label>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( 'Instellingen opslaan' ); ?>
                </form>
            </div>

            <!-- Zijpaneel -->
            <div>
                <!-- Status -->
                <div class="postbox">
                    <div class="postbox-header"><h2 class="hndle">Status</h2></div>
                    <div class="inside">
                        <p>
                            <strong>Scheduler:</strong>
                            <?php if ( $volgende ) : ?>
                                <span style="color:green;">&#10003; Actief</span><br>
                                Volgend bericht: <?php echo esc_html( wp_date( 'd-m-Y H:i', $volgende ) ); ?>
                            <?php else : ?>
                                <span style="color:#cc0000;">&#10007; Inactief</span>
                            <?php endif; ?>
                        </p>
                        <p>
                            <strong>Auto-updates:</strong>
                            <?php if ( ! empty( $settings['github_repo'] ) ) : ?>
                                <span style="color:green;">&#10003; GitHub actief</span><br>
                                <small><?php echo esc_html( $settings['github_repo'] ); ?></small>
                            <?php else : ?>
                                <span style="color:#888;">— Niet ingesteld</span>
                            <?php endif; ?>
                        </p>
                        <p>
                            <strong>Afbeeldingen:</strong>
                            <?php if ( ! empty( $settings['pexels_api_key'] ) ) : ?>
                                <span style="color:green;">&#10003; Pexels actief</span>
                            <?php else : ?>
                                <span style="color:#cc0000;">&#10007; Geen Pexels sleutel</span>
                            <?php endif; ?>
                        </p>
                        <p><strong>Huidig onderwerp:</strong><br>
                            <?php echo esc_html( arc_nieuws_get_huidig_onderwerp() ); ?>
                        </p>
                    </div>
                </div>

                <!-- Handmatig genereren -->
                <div class="postbox" style="margin-top:16px;">
                    <div class="postbox-header"><h2 class="hndle">Nu genereren</h2></div>
                    <div class="inside">
                        <p>Genereer direct een testbericht zonder te wachten op de dagelijkse planning.</p>
                        <form method="post">
                            <?php wp_nonce_field( 'arc_nieuws_genereer', 'arc_nieuws_genereer_nonce' ); ?>
                            <?php submit_button( 'Genereer nu', 'secondary', 'submit', false ); ?>
                        </form>
                    </div>
                </div>

                <!-- Onderwerpen -->
                <div class="postbox" style="margin-top:16px;">
                    <div class="postbox-header"><h2 class="hndle">Onderwerpen</h2></div>
                    <div class="inside">
                        <p style="margin-top:0;">Onderwerpen rouleren dagelijks:</p>
                        <ol style="margin-left:16px;">
                            <?php foreach ( arc_nieuws_get_onderwerpen() as $onderwerp ) : ?>
                                <li><?php echo esc_html( $onderwerp['naam'] ); ?></li>
                            <?php endforeach; ?>
                        </ol>
                    </div>
                </div>
            </div>

        </div><!-- /grid -->
    </div>
    <?php
}

// ─────────────────────────────────────────────
// Onderwerpen (diensten AllroundControl)
// ─────────────────────────────────────────────

function arc_nieuws_get_onderwerpen() {
    return array(
        array(
            'naam'          => 'Draadloze Netwerken',
            'pexels_query'  => 'wifi office business network',
            'prompt'        => 'Schrijf een professioneel nieuwsbericht (ca. 350 woorden) in het Nederlands over de voordelen van professioneel beheerde draadloze netwerken voor bedrijven. Schrijf in een zakelijke, maar toegankelijke stijl. Gebruik een pakkende kop als H2-tag en verdeel de tekst in twee korte alinea\'s met een tussenkop als H3-tag. Geef alleen de HTML-inhoud terug (geen <html>/<body> tags), begin direct met de H2-kop.',
        ),
        array(
            'naam'          => 'Managed Antivirus',
            'pexels_query'  => 'cybersecurity computer business',
            'prompt'        => 'Schrijf een professioneel nieuwsbericht (ca. 350 woorden) in het Nederlands over de noodzaak van managed antivirus en endpoint-beveiliging voor bedrijven. Schrijf in een zakelijke, maar toegankelijke stijl. Gebruik een pakkende kop als H2-tag en verdeel de tekst in twee korte alinea\'s met een tussenkop als H3-tag. Geef alleen de HTML-inhoud terug (geen <html>/<body> tags), begin direct met de H2-kop.',
        ),
        array(
            'naam'          => 'Professionele Websites',
            'pexels_query'  => 'web design laptop modern office',
            'prompt'        => 'Schrijf een professioneel nieuwsbericht (ca. 350 woorden) in het Nederlands over het belang van een professionele zakelijke website. Schrijf in een zakelijke, maar toegankelijke stijl. Gebruik een pakkende kop als H2-tag en verdeel de tekst in twee korte alinea\'s met een tussenkop als H3-tag. Geef alleen de HTML-inhoud terug (geen <html>/<body> tags), begin direct met de H2-kop.',
        ),
        array(
            'naam'          => 'Webshops',
            'pexels_query'  => 'online shopping ecommerce business',
            'prompt'        => 'Schrijf een professioneel nieuwsbericht (ca. 350 woorden) in het Nederlands over de kansen van een professionele webshop voor ondernemers. Schrijf in een zakelijke, maar toegankelijke stijl. Gebruik een pakkende kop als H2-tag en verdeel de tekst in twee korte alinea\'s met een tussenkop als H3-tag. Geef alleen de HTML-inhoud terug (geen <html>/<body> tags), begin direct met de H2-kop.',
        ),
        array(
            'naam'          => 'Webhosting',
            'pexels_query'  => 'server data center technology',
            'prompt'        => 'Schrijf een professioneel nieuwsbericht (ca. 350 woorden) in het Nederlands over de voordelen van betrouwbare, beheerde webhosting voor bedrijven. Schrijf in een zakelijke, maar toegankelijke stijl. Gebruik een pakkende kop als H2-tag en verdeel de tekst in twee korte alinea\'s met een tussenkop als H3-tag. Geef alleen de HTML-inhoud terug (geen <html>/<body> tags), begin direct met de H2-kop.',
        ),
        array(
            'naam'          => 'Social Media Beheer',
            'pexels_query'  => 'social media marketing smartphone',
            'prompt'        => 'Schrijf een professioneel nieuwsbericht (ca. 350 woorden) in het Nederlands over waarom consistente social media-aanwezigheid essentieel is voor bedrijven. Schrijf in een zakelijke, maar toegankelijke stijl. Gebruik een pakkende kop als H2-tag en verdeel de tekst in twee korte alinea\'s met een tussenkop als H3-tag. Geef alleen de HTML-inhoud terug (geen <html>/<body> tags), begin direct met de H2-kop.',
        ),
        array(
            'naam'          => 'IT-Beheer & Werkplekbeheer',
            'pexels_query'  => 'IT support office computer helpdesk',
            'prompt'        => 'Schrijf een professioneel nieuwsbericht (ca. 350 woorden) in het Nederlands over de voordelen van uitbesteed IT-beheer en werkplekbeheer voor mkb-ondernemers. Schrijf in een zakelijke, maar toegankelijke stijl. Gebruik een pakkende kop als H2-tag en verdeel de tekst in twee korte alinea\'s met een tussenkop als H3-tag. Geef alleen de HTML-inhoud terug (geen <html>/<body> tags), begin direct met de H2-kop.',
        ),
        array(
            'naam'          => 'Netwerkbeheer',
            'pexels_query'  => 'network cables router infrastructure',
            'prompt'        => 'Schrijf een professioneel nieuwsbericht (ca. 350 woorden) in het Nederlands over het belang van proactief netwerkbeheer voor bedrijven. Schrijf in een zakelijke, maar toegankelijke stijl. Gebruik een pakkende kop als H2-tag en verdeel de tekst in twee korte alinea\'s met een tussenkop als H3-tag. Geef alleen de HTML-inhoud terug (geen <html>/<body> tags), begin direct met de H2-kop.',
        ),
        array(
            'naam'          => 'Backup Oplossingen',
            'pexels_query'  => 'cloud backup data storage business',
            'prompt'        => 'Schrijf een professioneel nieuwsbericht (ca. 350 woorden) in het Nederlands over de risico\'s van geen goede back-up en het belang van automatische, betrouwbare backups voor bedrijfsdata. Schrijf in een zakelijke, maar toegankelijke stijl. Gebruik een pakkende kop als H2-tag en verdeel de tekst in twee korte alinea\'s met een tussenkop als H3-tag. Geef alleen de HTML-inhoud terug (geen <html>/<body> tags), begin direct met de H2-kop.',
        ),
        array(
            'naam'          => 'Cybersecurity',
            'pexels_query'  => 'cybersecurity protection business lock',
            'prompt'        => 'Schrijf een professioneel nieuwsbericht (ca. 350 woorden) in het Nederlands over actuele cyberdreigingen voor het mkb en hoe bedrijven zich kunnen beschermen. Schrijf in een zakelijke, maar toegankelijke stijl. Gebruik een pakkende kop als H2-tag en verdeel de tekst in twee korte alinea\'s met een tussenkop als H3-tag. Geef alleen de HTML-inhoud terug (geen <html>/<body> tags), begin direct met de H2-kop.',
        ),
    );
}

function arc_nieuws_get_huidig_onderwerp() {
    $settings    = arc_nieuws_get_settings();
    $onderwerpen = arc_nieuws_get_onderwerpen();
    $index       = absint( $settings['onderwerp_index'] ) % count( $onderwerpen );
    return $onderwerpen[ $index ]['naam'];
}

// ─────────────────────────────────────────────
// Tijdberekening
// ─────────────────────────────────────────────

function arc_nieuws_volgende_tijd( $uur ) {
    $uur     = intval( $uur );
    $nu      = time();
    $vandaag = mktime( $uur, 0, 0, gmdate( 'n' ), gmdate( 'j' ), gmdate( 'Y' ) );
    return ( $vandaag > $nu ) ? $vandaag : $vandaag + DAY_IN_SECONDS;
}

// ─────────────────────────────────────────────
// Cron-taak: dagelijks bericht genereren
// ─────────────────────────────────────────────

add_action( ARC_NIEUWS_CRON_HOOK, 'arc_nieuws_cron_uitvoeren' );

function arc_nieuws_cron_uitvoeren() {
    $settings = arc_nieuws_get_settings();
    if ( ! $settings['ingeschakeld'] ) {
        return;
    }
    arc_nieuws_genereer_bericht();
}

// ─────────────────────────────────────────────
// Bericht genereren via Claude API
// ─────────────────────────────────────────────

function arc_nieuws_genereer_bericht() {
    $settings = arc_nieuws_get_settings();
    $api_key  = $settings['api_key'];

    if ( empty( $api_key ) ) {
        return new WP_Error( 'geen_api_sleutel', 'Geen Anthropic API-sleutel ingesteld.' );
    }

    // Huidig onderwerp ophalen en index doorschuiven
    $onderwerpen = arc_nieuws_get_onderwerpen();
    $index       = absint( $settings['onderwerp_index'] ) % count( $onderwerpen );
    $onderwerp   = $onderwerpen[ $index ];

    // Index voor volgende keer bijwerken
    $settings['onderwerp_index'] = ( $index + 1 ) % count( $onderwerpen );
    update_option( ARC_NIEUWS_OPTION_KEY, $settings );

    // Claude API aanroepen
    $response = arc_nieuws_vraag_claude( $api_key, $settings['model'], $onderwerp['prompt'] );

    if ( is_wp_error( $response ) ) {
        error_log( '[AllroundControl Nieuws] Claude API fout: ' . $response->get_error_message() );
        return $response;
    }

    // Inhoud uit respons halen
    $inhoud = arc_nieuws_haal_tekst_uit_respons( $response );
    if ( is_wp_error( $inhoud ) ) {
        return $inhoud;
    }

    // Titel extraheren (eerste H2 uit de gegenereerde HTML)
    $titel = arc_nieuws_extraheer_titel( $inhoud, $onderwerp['naam'] );

    // WordPress bericht aanmaken
    $categorieen = $settings['categorie_id'] ? array( $settings['categorie_id'] ) : array();
    $post_id     = wp_insert_post( array(
        'post_title'    => $titel,
        'post_content'  => wp_kses_post( $inhoud ),
        'post_status'   => $settings['post_status'],
        'post_author'   => $settings['auteur_id'],
        'post_category' => $categorieen,
        'post_type'     => 'post',
        'meta_input'    => array(
            '_arc_nieuws_onderwerp'   => $onderwerp['naam'],
            '_arc_nieuws_gegenereerd' => current_time( 'mysql' ),
        ),
    ) );

    if ( is_wp_error( $post_id ) ) {
        return $post_id;
    }

    // Uitgelichte afbeelding ophalen via Pexels en instellen
    if ( ! empty( $settings['pexels_api_key'] ) && ! empty( $onderwerp['pexels_query'] ) ) {
        $attachment_id = arc_nieuws_pexels_afbeelding( $settings['pexels_api_key'], $onderwerp['pexels_query'], $post_id, $titel );
        if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
            set_post_thumbnail( $post_id, $attachment_id );
        } else {
            error_log( '[AllroundControl Nieuws] Pexels afbeelding mislukt voor post ' . $post_id );
        }
    }

    error_log( '[AllroundControl Nieuws] Bericht aangemaakt: ' . $post_id . ' — ' . $titel );
    return $post_id;
}

// ─────────────────────────────────────────────
// Pexels: afbeelding ophalen en uploaden
// ─────────────────────────────────────────────

function arc_nieuws_pexels_afbeelding( $pexels_key, $zoekterm, $post_id, $alt_tekst ) {
    // Willekeurige pagina (1-5) zodat berichten gevarieerde afbeeldingen krijgen
    $pagina = wp_rand( 1, 5 );

    $url  = add_query_arg( array(
        'query'    => rawurlencode( $zoekterm ),
        'per_page' => 15,
        'page'     => $pagina,
    ), 'https://api.pexels.com/v1/search' );

    $response = wp_remote_get( $url, array(
        'timeout' => 20,
        'headers' => array(
            'Authorization' => $pexels_key,
        ),
    ) );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
        return new WP_Error( 'pexels_fout', 'Pexels API HTTP ' . wp_remote_retrieve_response_code( $response ) );
    }

    $data   = json_decode( wp_remote_retrieve_body( $response ), true );
    $fotos  = $data['photos'] ?? array();

    if ( empty( $fotos ) ) {
        return new WP_Error( 'geen_fotos', 'Geen Pexels resultaten voor: ' . $zoekterm );
    }

    // Willekeurige foto uit de resultaten kiezen
    $foto      = $fotos[ array_rand( $fotos ) ];
    $afb_url   = $foto['src']['large'] ?? $foto['src']['original'] ?? '';
    $fotograaf = $foto['photographer'] ?? 'Pexels';

    if ( empty( $afb_url ) ) {
        return new WP_Error( 'geen_url', 'Geen afbeelding-URL gevonden in Pexels respons.' );
    }

    // Afbeelding downloaden naar WordPress media-bibliotheek
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $tijdelijk = download_url( $afb_url );

    if ( is_wp_error( $tijdelijk ) ) {
        return $tijdelijk;
    }

    $bestandsnaam = sanitize_file_name( 'arc-' . sanitize_title( $zoekterm ) . '-' . time() . '.jpg' );

    $bestand = array(
        'name'     => $bestandsnaam,
        'type'     => 'image/jpeg',
        'tmp_name' => $tijdelijk,
        'error'    => 0,
        'size'     => filesize( $tijdelijk ),
    );

    $attachment_id = media_handle_sideload( $bestand, $post_id, $alt_tekst );

    // Tijdelijk bestand opruimen als upload mislukt
    if ( is_wp_error( $attachment_id ) && file_exists( $tijdelijk ) ) {
        @unlink( $tijdelijk ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
    }

    if ( is_wp_error( $attachment_id ) ) {
        return $attachment_id;
    }

    // Alt-tekst en credit instellen
    update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_tekst ) );
    update_post_meta( $attachment_id, '_arc_pexels_credit', sanitize_text_field( 'Foto: ' . $fotograaf . ' via Pexels' ) );

    return $attachment_id;
}

// ─────────────────────────────────────────────
// Claude API aanroep
// ─────────────────────────────────────────────

function arc_nieuws_vraag_claude( $api_key, $model, $prompt ) {
    $body = wp_json_encode( array(
        'model'      => $model,
        'max_tokens' => 1024,
        'messages'   => array(
            array(
                'role'    => 'user',
                'content' => $prompt,
            ),
        ),
    ) );

    $args = array(
        'method'  => 'POST',
        'timeout' => 60,
        'headers' => array(
            'Content-Type'      => 'application/json',
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
        ),
        'body' => $body,
    );

    $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', $args );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $http_code = wp_remote_retrieve_response_code( $response );
    $body_raw  = wp_remote_retrieve_body( $response );

    if ( $http_code !== 200 ) {
        $decoded = json_decode( $body_raw, true );
        $fout    = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : 'HTTP ' . $http_code;
        return new WP_Error( 'claude_api_fout', $fout );
    }

    return json_decode( $body_raw, true );
}

// ─────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────

function arc_nieuws_haal_tekst_uit_respons( $respons ) {
    if ( ! isset( $respons['content'] ) || ! is_array( $respons['content'] ) ) {
        return new WP_Error( 'ongeldige_respons', 'Onverwachte API-respons structuur.' );
    }
    foreach ( $respons['content'] as $blok ) {
        if ( isset( $blok['type'] ) && 'text' === $blok['type'] && ! empty( $blok['text'] ) ) {
            return trim( $blok['text'] );
        }
    }
    return new WP_Error( 'geen_tekst', 'Geen tekstblok gevonden in API-respons.' );
}

function arc_nieuws_extraheer_titel( $html, $fallback ) {
    if ( preg_match( '/<h2[^>]*>(.*?)<\/h2>/is', $html, $match ) ) {
        return wp_strip_all_tags( $match[1] );
    }
    $tekst  = wp_strip_all_tags( $html );
    $regels = array_filter( explode( "\n", $tekst ) );
    $eerste = reset( $regels );
    if ( $eerste && mb_strlen( $eerste ) < 120 ) {
        return $eerste;
    }
    return 'Nieuws: ' . $fallback . ' — ' . wp_date( 'd-m-Y' );
}

// ─────────────────────────────────────────────
// Plugin-actilink in beheerpaneel
// ─────────────────────────────────────────────

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'arc_nieuws_actie_links' );

function arc_nieuws_actie_links( $links ) {
    $instellingen_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=arc-nieuws-generator' ) ) . '">Instellingen</a>';
    array_unshift( $links, $instellingen_link );
    return $links;
}

// ─────────────────────────────────────────────
// GitHub Auto-updater
// ─────────────────────────────────────────────

class ARC_Nieuws_Updater {

    private $github_repo;
    private $plugin_slug;
    private $plugin_file;
    private $version;

    public function __construct( $github_repo, $plugin_slug, $plugin_file, $version ) {
        $this->github_repo = $github_repo;
        $this->plugin_slug = $plugin_slug;
        $this->plugin_file = $plugin_file;
        $this->version     = $version;

        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'controleer_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
        add_filter( 'upgrader_source_selection', array( $this, 'herstel_mapnaam' ), 10, 4 );
    }

    /**
     * Haal de nieuwste release-info op van GitHub (gecached 12 uur).
     */
    private function haal_github_release() {
        $cache_key = 'arc_nieuws_github_release';
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $url      = 'https://api.github.com/repos/' . $this->github_repo . '/releases/latest';
        $response = wp_remote_get( $url, array(
            'timeout' => 15,
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ),
            ),
        ) );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['tag_name'] ) ) {
            return false;
        }

        set_transient( $cache_key, $data, 12 * HOUR_IN_SECONDS );
        return $data;
    }

    /**
     * Injecteert update-info in de WordPress plugin-transient.
     */
    public function controleer_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->haal_github_release();
        if ( ! $release ) {
            return $transient;
        }

        // Versienummer opschonen (verwijder 'v' prefix: v1.2.0 → 1.2.0)
        $nieuwste_versie = ltrim( $release['tag_name'], 'vV' );

        if ( version_compare( $nieuwste_versie, $this->version, '>' ) ) {
            $transient->response[ $this->plugin_slug ] = (object) array(
                'slug'        => dirname( $this->plugin_slug ),
                'plugin'      => $this->plugin_slug,
                'new_version' => $nieuwste_versie,
                'url'         => 'https://github.com/' . $this->github_repo,
                'package'     => $release['zipball_url'] ?? '',
                'icons'       => array(),
                'banners'     => array(),
                'tested'      => get_bloginfo( 'version' ),
                'requires_php' => '7.4',
            );
        }

        return $transient;
    }

    /**
     * Vult de "Bekijk details" popup in het WordPress-dashboard.
     */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }
        if ( ! isset( $args->slug ) || dirname( $this->plugin_slug ) !== $args->slug ) {
            return $result;
        }

        $release = $this->haal_github_release();
        if ( ! $release ) {
            return $result;
        }

        $versie = ltrim( $release['tag_name'], 'vV' );

        return (object) array(
            'name'          => 'AllroundControl Nieuws Generator',
            'slug'          => dirname( $this->plugin_slug ),
            'version'       => $versie,
            'author'        => '<a href="https://allroundcontrol.nl">AllroundControl</a>',
            'homepage'      => 'https://github.com/' . $this->github_repo,
            'requires'      => '5.8',
            'requires_php'  => '7.4',
            'tested'        => get_bloginfo( 'version' ),
            'last_updated'  => $release['published_at'] ?? '',
            'sections'      => array(
                'description' => $release['body'] ?? 'Dagelijkse nieuwsberichten generator voor AllroundControl.',
                'changelog'   => '<p>' . nl2br( esc_html( $release['body'] ?? '' ) ) . '</p>',
            ),
            'download_link' => $release['zipball_url'] ?? '',
        );
    }

    /**
     * GitHub ZIPs pakken bestanden in een map met willekeurige naam (bijv. repo-abc123).
     * Dit hernoemt die map naar de correcte plugin-mapnaam.
     */
    public function herstel_mapnaam( $source, $remote_source, $upgrader, $hook_extra ) {
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_slug ) {
            return $source;
        }

        $juiste_map = trailingslashit( $remote_source ) . dirname( $this->plugin_slug );

        if ( $source !== $juiste_map ) {
            global $wp_filesystem;
            if ( $wp_filesystem->move( $source, $juiste_map ) ) {
                return trailingslashit( $juiste_map );
            }
        }

        return $source;
    }
}

// Updater initialiseren als GitHub-repo is ingesteld
add_action( 'init', function() {
    $settings = arc_nieuws_get_settings();
    if ( ! empty( $settings['github_repo'] ) ) {
        new ARC_Nieuws_Updater(
            $settings['github_repo'],
            ARC_NIEUWS_PLUGIN_SLUG,
            __FILE__,
            ARC_NIEUWS_VERSION
        );
    }
} );
