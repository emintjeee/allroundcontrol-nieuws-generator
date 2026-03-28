<?php
/**
 * Plugin Name:       AllroundControl Nieuws Generator
 * Plugin URI:        https://allroundcontrol.nl
 * Description:       Genereert dagelijks automatisch nieuwsberichten over de diensten van AllroundControl via de Claude AI API. Inclusief automatische uitgelichte afbeelding via Pexels.
 * Version:           1.4.0
 * Author:            AllroundControl
 * Author URI:        https://allroundcontrol.nl
 * License:           GPL-2.0+
 * Text Domain:       arc-nieuws
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ARC_NIEUWS_VERSION',     '1.4.0' );
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
        'claude-haiku-4-5'  => array( 'label' => 'Claude Haiku 4.5', 'sub' => 'Snel &amp; voordelig', 'icon' => '⚡' ),
        'claude-sonnet-4-6' => array( 'label' => 'Claude Sonnet 4.6', 'sub' => 'Uitgebreide kwaliteit', 'icon' => '✦' ),
        'claude-opus-4-6'   => array( 'label' => 'Claude Opus 4.6', 'sub' => 'Beste kwaliteit', 'icon' => '★' ),
    );
    $huidig_onderwerp = arc_nieuws_get_huidig_onderwerp();
    $alle_onderwerpen = arc_nieuws_get_onderwerpen();
    ?>
    <style>
        /* ── Reset & basis ───────────────────────── */
        #arc-wrap * { box-sizing: border-box; }
        #arc-wrap { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #1e293b; }

        /* ── Header banner ───────────────────────── */
        .arc-header {
            background: linear-gradient(135deg, #1e40af 0%, #4f46e5 50%, #7c3aed 100%);
            border-radius: 16px;
            padding: 32px 36px;
            margin-bottom: 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 8px 32px rgba(79,70,229,.25);
        }
        .arc-header-left h1 {
            color: #fff !important;
            font-size: 24px !important;
            font-weight: 700 !important;
            margin: 0 0 4px !important;
            padding: 0 !important;
            line-height: 1.2 !important;
            text-shadow: 0 1px 3px rgba(0,0,0,.2);
        }
        .arc-header-left p { color: rgba(255,255,255,.75); margin: 0; font-size: 14px; }
        .arc-version-badge {
            background: rgba(255,255,255,.15);
            color: #fff;
            border: 1px solid rgba(255,255,255,.25);
            border-radius: 20px;
            padding: 6px 14px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: .5px;
            backdrop-filter: blur(4px);
        }

        /* ── Notificaties ────────────────────────── */
        .arc-alert {
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            border: none !important;
        }
        .arc-alert-success { background: #f0fdf4; border-left: 4px solid #22c55e !important; color: #166534; }
        .arc-alert-error   { background: #fef2f2; border-left: 4px solid #ef4444 !important; color: #991b1b; }
        .arc-alert .arc-alert-icon { font-size: 18px; flex-shrink: 0; }

        /* ── Grid layout ─────────────────────────── */
        .arc-grid { display: grid; grid-template-columns: 1fr 320px; gap: 24px; align-items: start; }

        /* ── Cards ───────────────────────────────── */
        .arc-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 1px 3px rgba(0,0,0,.06), 0 4px 16px rgba(0,0,0,.06);
            overflow: hidden;
            margin-bottom: 20px;
        }
        .arc-card-header {
            padding: 18px 24px 14px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .arc-card-header h2 {
            font-size: 15px !important;
            font-weight: 600 !important;
            color: #1e293b !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        .arc-card-icon {
            width: 32px; height: 32px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        .arc-card-icon.blue   { background: #eff6ff; }
        .arc-card-icon.purple { background: #f5f3ff; }
        .arc-card-icon.green  { background: #f0fdf4; }
        .arc-card-icon.orange { background: #fff7ed; }
        .arc-card-body { padding: 20px 24px; }

        /* ── Formulier ───────────────────────────── */
        .arc-field-group { margin-bottom: 20px; }
        .arc-field-group:last-child { margin-bottom: 0; }
        .arc-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }
        .arc-label span { font-weight: 400; color: #6b7280; }
        .arc-input, .arc-select {
            width: 100%;
            padding: 9px 13px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            color: #1e293b;
            background: #fff;
            transition: border-color .15s, box-shadow .15s;
            outline: none;
        }
        .arc-input:focus, .arc-select:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99,102,241,.12);
        }
        .arc-hint { font-size: 12px; color: #94a3b8; margin-top: 5px; }
        .arc-hint a { color: #6366f1; text-decoration: none; }
        .arc-hint a:hover { text-decoration: underline; }

        /* ── Model kiezer ────────────────────────── */
        .arc-model-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 8px; }
        .arc-model-option { display: none; }
        .arc-model-card {
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px;
            cursor: pointer;
            transition: all .15s;
            text-align: center;
            user-select: none;
        }
        .arc-model-card:hover { border-color: #a5b4fc; background: #fafafa; }
        .arc-model-option:checked + .arc-model-card {
            border-color: #6366f1;
            background: #f5f3ff;
            box-shadow: 0 0 0 3px rgba(99,102,241,.1);
        }
        .arc-model-card .arc-model-icon { font-size: 22px; margin-bottom: 4px; }
        .arc-model-card .arc-model-name { font-size: 12px; font-weight: 600; color: #1e293b; }
        .arc-model-card .arc-model-sub  { font-size: 11px; color: #94a3b8; margin-top: 2px; }

        /* ── Toggle schakelaar ───────────────────── */
        .arc-toggle-wrap { display: flex; align-items: center; gap: 12px; }
        .arc-toggle { position: relative; display: inline-block; width: 44px; height: 24px; flex-shrink: 0; }
        .arc-toggle input { opacity: 0; width: 0; height: 0; }
        .arc-slider {
            position: absolute; inset: 0;
            background: #cbd5e1; border-radius: 24px; cursor: pointer;
            transition: background .2s;
        }
        .arc-slider:before {
            content: '';
            position: absolute;
            width: 18px; height: 18px;
            left: 3px; bottom: 3px;
            background: #fff; border-radius: 50%;
            transition: transform .2s;
            box-shadow: 0 1px 3px rgba(0,0,0,.2);
        }
        .arc-toggle input:checked + .arc-slider { background: #6366f1; }
        .arc-toggle input:checked + .arc-slider:before { transform: translateX(20px); }
        .arc-toggle-label { font-size: 14px; color: #374151; }

        /* ── Opslaan knop ────────────────────────── */
        .arc-btn-save {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: #fff !important;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity .15s, transform .1s;
            margin-top: 4px;
            box-shadow: 0 4px 12px rgba(99,102,241,.3);
            text-align: center;
        }
        .arc-btn-save:hover { opacity: .92; transform: translateY(-1px); }
        .arc-btn-save:active { transform: translateY(0); }

        /* ── Status badges ───────────────────────── */
        .arc-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 10px; border-radius: 20px;
            font-size: 12px; font-weight: 600;
        }
        .arc-badge-green  { background: #dcfce7; color: #15803d; }
        .arc-badge-red    { background: #fee2e2; color: #b91c1c; }
        .arc-badge-gray   { background: #f1f5f9; color: #64748b; }
        .arc-badge-blue   { background: #dbeafe; color: #1d4ed8; }

        /* ── Status rijen ────────────────────────── */
        .arc-status-row {
            display: flex; align-items: center; justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13px;
        }
        .arc-status-row:last-child { border-bottom: none; padding-bottom: 0; }
        .arc-status-label { color: #64748b; font-weight: 500; }

        /* ── Genereer knop ───────────────────────── */
        .arc-btn-generate {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #0f172a, #1e40af);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity .15s;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            box-shadow: 0 4px 12px rgba(30,64,175,.25);
        }
        .arc-btn-generate:hover { opacity: .88; }

        /* ── Onderwerpen pills ───────────────────── */
        .arc-topics { display: flex; flex-wrap: wrap; gap: 6px; }
        .arc-topic-pill {
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 20px;
            padding: 4px 12px;
            font-size: 12px;
            color: #475569;
            transition: all .15s;
        }
        .arc-topic-pill.active {
            background: #f5f3ff;
            border-color: #a5b4fc;
            color: #4f46e5;
            font-weight: 600;
        }

        /* ── Update knop ─────────────────────────── */
        .arc-btn-update {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 12px;
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            font-size: 12px; font-weight: 600;
            color: #374151;
            cursor: pointer; text-decoration: none;
            transition: all .15s; margin-top: 8px;
        }
        .arc-btn-update:hover { background: #f1f5f9; border-color: #cbd5e1; color: #1e293b; }

        /* ── Volgende tijd ───────────────────────── */
        .arc-next-time {
            font-size: 12px; color: #94a3b8; margin-top: 3px;
        }
    </style>

    <div class="wrap" id="arc-wrap">

        <!-- Header -->
        <div class="arc-header">
            <div class="arc-header-left">
                <h1>⚡ Nieuws Generator</h1>
                <p>Dagelijkse AI-nieuwsberichten voor AllroundControl</p>
            </div>
            <div class="arc-version-badge">v<?php echo esc_html( ARC_NIEUWS_VERSION ); ?></div>
        </div>

        <!-- Notificaties -->
        <?php if ( $bericht ) :
            $is_error = strpos( $bericht, 'notice-error' ) !== false;
            $tekst    = wp_strip_all_tags( $bericht );
            $tekst    = preg_replace( '/Fout:\s*/', '', $tekst );
        ?>
        <div class="arc-alert <?php echo $is_error ? 'arc-alert-error' : 'arc-alert-success'; ?>">
            <span class="arc-alert-icon"><?php echo $is_error ? '✕' : '✓'; ?></span>
            <span><?php echo wp_kses( $bericht, array( 'a' => array( 'href' => array() ) ) ); ?></span>
        </div>
        <?php endif; ?>

        <div class="arc-grid">

            <!-- Linker kolom: instellingen -->
            <div>
                <form method="post">
                    <?php wp_nonce_field( 'arc_nieuws_opslaan', 'arc_nieuws_nonce' ); ?>

                    <!-- API Sleutels -->
                    <div class="arc-card">
                        <div class="arc-card-header">
                            <div class="arc-card-icon blue">🔑</div>
                            <h2>API-sleutels</h2>
                        </div>
                        <div class="arc-card-body">
                            <div class="arc-field-group">
                                <label class="arc-label" for="api_key">Anthropic API-sleutel</label>
                                <input type="password" id="api_key" name="api_key" class="arc-input"
                                    value="<?php echo esc_attr( $settings['api_key'] ); ?>" autocomplete="off"
                                    placeholder="sk-ant-..." />
                                <p class="arc-hint">Aanmaken via <a href="https://console.anthropic.com/" target="_blank" rel="noopener">console.anthropic.com</a></p>
                            </div>
                            <div class="arc-field-group">
                                <label class="arc-label" for="pexels_api_key">Pexels API-sleutel <span>(uitgelichte afbeeldingen)</span></label>
                                <input type="password" id="pexels_api_key" name="pexels_api_key" class="arc-input"
                                    value="<?php echo esc_attr( $settings['pexels_api_key'] ); ?>" autocomplete="off"
                                    placeholder="Gratis via pexels.com/api" />
                                <p class="arc-hint">Gratis account via <a href="https://www.pexels.com/api/" target="_blank" rel="noopener">pexels.com/api</a></p>
                            </div>
                            <div class="arc-field-group" style="margin-bottom:0;">
                                <label class="arc-label" for="github_repo">GitHub repository <span>(auto-updates)</span></label>
                                <input type="text" id="github_repo" name="github_repo" class="arc-input"
                                    value="<?php echo esc_attr( $settings['github_repo'] ); ?>"
                                    placeholder="gebruikersnaam/allroundcontrol-nieuws-generator" />
                                <p class="arc-hint">Format: <code>gebruikersnaam/repository</code></p>
                            </div>
                        </div>
                    </div>

                    <!-- AI-model -->
                    <div class="arc-card">
                        <div class="arc-card-header">
                            <div class="arc-card-icon purple">🤖</div>
                            <h2>AI-model</h2>
                        </div>
                        <div class="arc-card-body">
                            <div class="arc-model-grid">
                                <?php foreach ( $modellen as $waarde => $info ) : ?>
                                    <label>
                                        <input type="radio" name="model" value="<?php echo esc_attr( $waarde ); ?>"
                                            class="arc-model-option" <?php checked( $settings['model'], $waarde ); ?>>
                                        <div class="arc-model-card">
                                            <div class="arc-model-icon"><?php echo esc_html( $info['icon'] ); ?></div>
                                            <div class="arc-model-name"><?php echo esc_html( $info['label'] ); ?></div>
                                            <div class="arc-model-sub"><?php echo wp_kses_post( $info['sub'] ); ?></div>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Publicatie-instellingen -->
                    <div class="arc-card">
                        <div class="arc-card-header">
                            <div class="arc-card-icon green">📝</div>
                            <h2>Publicatie-instellingen</h2>
                        </div>
                        <div class="arc-card-body">
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                                <div class="arc-field-group" style="margin:0;">
                                    <label class="arc-label" for="auteur_id">Auteur</label>
                                    <select id="auteur_id" name="auteur_id" class="arc-select">
                                        <?php foreach ( $gebruikers as $g ) : ?>
                                            <option value="<?php echo esc_attr( $g->ID ); ?>" <?php selected( $settings['auteur_id'], $g->ID ); ?>>
                                                <?php echo esc_html( $g->display_name ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="arc-field-group" style="margin:0;">
                                    <label class="arc-label" for="categorie_id">Categorie</label>
                                    <select id="categorie_id" name="categorie_id" class="arc-select">
                                        <option value="0">— Geen —</option>
                                        <?php foreach ( $categorieen as $cat ) : ?>
                                            <option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( $settings['categorie_id'], $cat->term_id ); ?>>
                                                <?php echo esc_html( $cat->name ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="arc-field-group" style="margin:0;">
                                    <label class="arc-label" for="post_status">Status</label>
                                    <select id="post_status" name="post_status" class="arc-select">
                                        <option value="publish" <?php selected( $settings['post_status'], 'publish' ); ?>>✓ Publiceren</option>
                                        <option value="draft"   <?php selected( $settings['post_status'], 'draft' ); ?>>✎ Concept</option>
                                    </select>
                                </div>
                                <div class="arc-field-group" style="margin:0;">
                                    <label class="arc-label" for="uur">Tijdstip</label>
                                    <select id="uur" name="uur" class="arc-select">
                                        <?php for ( $h = 0; $h < 24; $h++ ) : ?>
                                            <option value="<?php echo esc_attr( $h ); ?>" <?php selected( $settings['uur'], $h ); ?>>
                                                <?php echo esc_html( sprintf( '%02d:00', $h ) ); ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                    <p class="arc-hint">Servertijd (UTC <?php echo esc_html( gmdate( 'H:i' ) ); ?>)</p>
                                </div>
                            </div>
                            <div style="margin-top:20px;padding-top:16px;border-top:1px solid #f1f5f9;">
                                <div class="arc-toggle-wrap">
                                    <label class="arc-toggle">
                                        <input type="checkbox" name="ingeschakeld" value="1" <?php checked( $settings['ingeschakeld'], 1 ); ?> />
                                        <span class="arc-slider"></span>
                                    </label>
                                    <span class="arc-toggle-label">Dagelijks automatisch een bericht genereren</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="arc-btn-save">💾 &nbsp;Instellingen opslaan</button>
                </form>
            </div>

            <!-- Rechter kolom: status & acties -->
            <div>

                <!-- Status -->
                <div class="arc-card">
                    <div class="arc-card-header">
                        <div class="arc-card-icon blue">📊</div>
                        <h2>Status</h2>
                    </div>
                    <div class="arc-card-body" style="padding-top:12px;padding-bottom:12px;">
                        <div class="arc-status-row">
                            <span class="arc-status-label">Scheduler</span>
                            <?php if ( $volgende ) : ?>
                                <div>
                                    <span class="arc-badge arc-badge-green">● Actief</span>
                                    <div class="arc-next-time">⏰ <?php echo esc_html( wp_date( 'd-m-Y H:i', $volgende ) ); ?></div>
                                </div>
                            <?php else : ?>
                                <span class="arc-badge arc-badge-red">● Inactief</span>
                            <?php endif; ?>
                        </div>
                        <div class="arc-status-row">
                            <span class="arc-status-label">Afbeeldingen</span>
                            <?php if ( ! empty( $settings['pexels_api_key'] ) ) : ?>
                                <span class="arc-badge arc-badge-green">● Pexels</span>
                            <?php else : ?>
                                <span class="arc-badge arc-badge-gray">Niet ingesteld</span>
                            <?php endif; ?>
                        </div>
                        <div class="arc-status-row">
                            <span class="arc-status-label">Auto-updates</span>
                            <?php if ( ! empty( $settings['github_repo'] ) ) : ?>
                                <span class="arc-badge arc-badge-blue">● GitHub</span>
                            <?php else : ?>
                                <span class="arc-badge arc-badge-gray">Niet ingesteld</span>
                            <?php endif; ?>
                        </div>
                        <?php if ( ! empty( $settings['github_repo'] ) ) : ?>
                        <div style="padding-top:8px;">
                            <?php if ( isset( $_GET['arc_cache_cleared'] ) ) : ?>
                                <p style="font-size:12px;color:#15803d;margin:0 0 8px;">✓ Cache geleegd.</p>
                            <?php endif; ?>
                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=arc-nieuws-generator&arc_clear_cache=1' ), 'arc_clear_cache' ) ); ?>"
                               class="arc-btn-update">↺ Forceer update check</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Nu genereren -->
                <div class="arc-card">
                    <div class="arc-card-header">
                        <div class="arc-card-icon orange">✨</div>
                        <h2>Nu genereren</h2>
                    </div>
                    <div class="arc-card-body">
                        <p style="font-size:13px;color:#64748b;margin:0 0 14px;">
                            Volgende onderwerp: <strong style="color:#4f46e5;"><?php echo esc_html( $huidig_onderwerp ); ?></strong>
                        </p>
                        <form method="post">
                            <?php wp_nonce_field( 'arc_nieuws_genereer', 'arc_nieuws_genereer_nonce' ); ?>
                            <button type="submit" class="arc-btn-generate">
                                <span>⚡</span> Genereer bericht
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Onderwerpen -->
                <div class="arc-card">
                    <div class="arc-card-header">
                        <div class="arc-card-icon purple">🗂</div>
                        <h2>Onderwerpen</h2>
                    </div>
                    <div class="arc-card-body">
                        <div class="arc-topics">
                            <?php foreach ( $alle_onderwerpen as $onderwerp ) : ?>
                                <span class="arc-topic-pill <?php echo ( $onderwerp['naam'] === $huidig_onderwerp ) ? 'active' : ''; ?>">
                                    <?php echo esc_html( $onderwerp['naam'] ); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <p class="arc-hint" style="margin-top:12px;">Het gemarkeerde onderwerp wordt als volgende gebruikt.</p>
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
            // Directe ZIP-URL (geen API-redirect nodig, werkt altijd voor publieke repos)
            $zip_url = 'https://github.com/' . $this->github_repo . '/archive/refs/tags/' . $release['tag_name'] . '.zip';

            $transient->response[ $this->plugin_slug ] = (object) array(
                'slug'        => dirname( $this->plugin_slug ),
                'plugin'      => $this->plugin_slug,
                'new_version' => $nieuwste_versie,
                'url'         => 'https://github.com/' . $this->github_repo,
                'package'     => $zip_url,
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
            'download_link' => 'https://github.com/' . $this->github_repo . '/archive/refs/tags/' . ( $release['tag_name'] ?? '' ) . '.zip',
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

        global $wp_filesystem;
        $plugin_map  = dirname( $this->plugin_slug ); // bijv. "allroundcontrol-nieuws-generator"
        $juiste_map  = trailingslashit( $remote_source ) . $plugin_map;

        // Al correct
        if ( trailingslashit( $source ) === trailingslashit( $juiste_map ) ) {
            return $source;
        }

        // GitHub archive ZIP levert een map als "repo-v1.3.0" of "repo-1.3.0"
        // $source is het pad dat WordPress al heeft uitgepakt
        if ( $wp_filesystem->move( $source, $juiste_map ) ) {
            return trailingslashit( $juiste_map );
        }

        return $source;
    }
}

// Updater initialiseren zo vroeg mogelijk (plugins_loaded)
add_action( 'plugins_loaded', function() {
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

// Cache leegmaken via knop in het dashboard
add_action( 'admin_init', function() {
    if (
        isset( $_GET['arc_clear_cache'] ) &&
        current_user_can( 'manage_options' ) &&
        wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'arc_clear_cache' )
    ) {
        delete_transient( 'arc_nieuws_github_release' );
        delete_site_transient( 'update_plugins' );
        wp_redirect( admin_url( 'options-general.php?page=arc-nieuws-generator&arc_cache_cleared=1' ) );
        exit;
    }
} );
