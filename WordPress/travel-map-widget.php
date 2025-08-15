<?php
/**
 * Plugin Name: Travel Map Widget
 * Plugin URI: https://github.com/TonyCicero/Travel-Map-Widget
 * Description: An interactive travel map widget with toggle between flat map and globe, managed locations via checkboxes, configurable permalinks, and customizable colors.
 * Version: 1.1.0
 * Author: Tony Cicero
 * Author URI: https://github.com/TonyCicero
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: travel-map-widget
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// PHP helper function to convert hex to RGB for form processing
function hexToRgb($hex) {
    $hex = str_replace('#', '', $hex);
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return "$r, $g, $b";
}

// PHP helper function to extract hex from rgba
function hexFromRgba($rgba) {
    if (preg_match('/^rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*[\d.]+)?\)$/', $rgba, $matches)) {
        $r = str_pad(dechex($matches[1]), 2, '0', STR_PAD_LEFT);
        $g = str_pad(dechex($matches[2]), 2, '0', STR_PAD_LEFT);
        $b = str_pad(dechex($matches[3]), 2, '0', STR_PAD_LEFT);
        return "#$r$g$b";
    }
    return '#9100b4'; // Default hex if parsing fails
}

// Enqueue scripts and styles
function travel_map_widget_enqueue_scripts() {
    // Leaflet CSS and JS
    wp_enqueue_style('leaflet-css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
    wp_enqueue_script('leaflet-js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);

    // Three.js and Globe.GL
    wp_enqueue_script('three-js', 'https://cdnjs.cloudflare.com/ajax/libs/three.js/r134/three.min.js', [], 'r134', true);
    wp_enqueue_script('globe-gl', 'https://unpkg.com/globe.gl@2.27.0/dist/globe.gl.min.js', ['three-js'], '2.27.0', true);

    // Plugin CSS
    $css_path = plugin_dir_url(__FILE__) . 'travel-map-widget.css';
    if (file_exists(plugin_dir_path(__FILE__) . 'travel-map-widget.css')) {
        wp_enqueue_style('travel-map-widget-css', $css_path, [], '1.3.0');
    } else {
        wp_enqueue_style('travel-map-widget-css', '', [], '1.3.0'); // Fallback to avoid breaking
    }

    // Pass data to JS
    $displayed_locations = get_option('travel_map_displayed_locations', '');
    $locations_array = !empty($displayed_locations) ? array_map('trim', explode(',', $displayed_locations)) : [
        'California', 'Florida', 'Maryland', 'Massachusetts', 'New York', 'Nevada', 'Pennsylvania', 'Virginia',
        'Albania', 'Austria', 'Belgium', 'Cambodia', 'Canada', 'Czech Republic', 'Denmark', 'Dominican Republic',
        'France', 'Germany', 'Greece', 'Ireland', 'Japan', 'Laos', 'Netherlands', 'Poland', 'Slovakia', 'Sweden',
        'Switzerland', 'Thailand', 'United Kingdom', 'United States of America', 'Vietnam'
    ];
    $permalink_base = get_option('travel_map_permalink_base', '/wp/location/');
    $country_fill_color = get_option('travel_map_country_fill_color', '#9000b4');
    $country_border_color = get_option('travel_map_country_border_color', '#ffffff');
    $country_fill_opacity = get_option('travel_map_country_fill_opacity', '0.3');
    $us_state_fill_color = get_option('travel_map_us_state_fill_color', '#00aaff');
    $us_state_border_color = get_option('travel_map_us_state_border_color', '#ffffff');
    $us_state_fill_opacity = get_option('travel_map_us_state_fill_opacity', '0.3');
    $globe_cap_color = get_option('travel_map_globe_cap_color', 'rgba(145, 0, 180, 0.3)');
    $globe_cap_opacity = get_option('travel_map_globe_cap_opacity', '0.3');
    $globe_side_color = get_option('travel_map_globe_side_color', 'rgba(0, 100, 0, 0.15)');
    $globe_side_opacity = get_option('travel_map_globe_side_opacity', '0.15');
    $globe_stroke_color = get_option('travel_map_globe_stroke_color', '#111111');
    $globe_background_color = get_option('travel_map_globe_background_color', '#000000');
    wp_localize_script('leaflet-js', 'travelMapVars', [
        'baseUrl' => home_url(),
        'displayedLocations' => $locations_array,
        'permalinkBase' => $permalink_base,
        'countryFillColor' => $country_fill_color,
        'countryBorderColor' => $country_border_color,
        'countryFillOpacity' => floatval($country_fill_opacity),
        'usStateFillColor' => $us_state_fill_color,
        'usStateBorderColor' => $us_state_border_color,
        'usStateFillOpacity' => floatval($us_state_fill_opacity),
        'globeCapColor' => $globe_cap_color,
        'globeCapOpacity' => floatval($globe_cap_opacity),
        'globeSideColor' => $globe_side_color,
        'globeSideOpacity' => floatval($globe_side_opacity),
        'globeStrokeColor' => $globe_stroke_color,
        'globeBackgroundColor' => $globe_background_color
    ]);
}
add_action('wp_enqueue_scripts', 'travel_map_widget_enqueue_scripts');

// Shortcode to display the map widget
function travel_map_widget_shortcode() {
    ob_start();
    ?>
    <div id="map-container">
        <div class="toggle-container">
            <input type="checkbox" id="toggle-btn" aria-label="Toggle between flat map and globe">
            <label for="toggle-btn" class="toggle-label">
                <span class="toggle-text map">Map</span>
                <span class="toggle-text globe">Globe</span>
            </label>
        </div>
        <div id="error-message"></div>
        <div id="flat-map" class="active"></div>
        <div id="globe"></div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof travelMapVars === 'undefined') {
                console.error('travelMapVars is not defined. Ensure scripts are loaded correctly.');
                document.getElementById('error-message').style.display = 'block';
                document.getElementById('error-message').textContent = 'Error: Map configuration not loaded. Please refresh the page.';
                return;
            }

            const baseUrl = travelMapVars.baseUrl;
            const displayedLocations = travelMapVars.displayedLocations;
            const permalinkBase = travelMapVars.permalinkBase;
            const countryFillColor = travelMapVars.countryFillColor;
            const countryBorderColor = travelMapVars.countryBorderColor;
            const countryFillOpacity = travelMapVars.countryFillOpacity || 0.3;
            const usStateFillColor = travelMapVars.usStateFillColor;
            const usStateBorderColor = travelMapVars.usStateBorderColor;
            const usStateFillOpacity = travelMapVars.usStateFillOpacity || 0.3;
            const globeCapColor = travelMapVars.globeCapColor;
            const globeCapOpacity = travelMapVars.globeCapOpacity || 0.3;
            const globeSideColor = travelMapVars.globeSideColor;
            const globeSideOpacity = travelMapVars.globeSideOpacity || 0.15;
            const globeStrokeColor = travelMapVars.globeStrokeColor;
            const globeBackgroundColor = travelMapVars.globeBackgroundColor;

            const showError = (message) => {
                const errorDiv = document.getElementById('error-message');
                errorDiv.style.display = 'block';
                errorDiv.textContent = message;
            };

            const isValidGeometry = (geometry) => {
                if (!geometry || !geometry.type || !geometry.coordinates) return false;
                if (geometry.type === 'Polygon' || geometry.type === 'MultiPolygon') {
                    const coords = geometry.type === 'Polygon' ? [geometry.coordinates] : geometry.coordinates;
                    for (const poly of coords) {
                        for (const ring of poly) {
                            for (const [lon, lat] of ring) {
                                if (Math.abs(lon) > 180 || Math.abs(lat) > 90) return false;
                            }
                        }
                    }
                }
                return true;
            };

            const flatMap = L.map('flat-map').setView([20, 0], 2);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                maxZoom: 18,
                minZoom: 2
            }).addTo(flatMap).bringToBack();

            const countryGeoJsonUrl = 'https://raw.githubusercontent.com/TonyCicero/Map-Widget/refs/heads/main/world.geo.json';
            const usStatesGeoJsonUrl = 'https://raw.githubusercontent.com/TonyCicero/Map-Widget/refs/heads/main/us_states.geo.json';

            fetch(countryGeoJsonUrl)
                .then(response => response.json())
                .then(data => {
                    if (!data.features) throw new Error('Invalid GeoJSON: No features found');
                    data.features = data.features.filter(feature => 
                        isValidGeometry(feature.geometry) && displayedLocations.includes(feature.properties.name)
                    );
                    if (data.features.length === 0) {
                        showError('No matching countries found in GeoJSON.');
                        return;
                    }
                    L.geoJSON(data, {
                        style: {
                            fillColor: countryFillColor,
                            weight: 2,
                            opacity: 1,
                            color: countryBorderColor,
                            fillOpacity: countryFillOpacity
                        },
                        onEachFeature: (feature, layer) => {
                            const name = feature.properties.name || 'Unknown';
                            const slug = name.toLowerCase().replace(/\s+/g, '-').trim();
                            let hoverTimeout;
                            layer.on('mouseover', () => {
                                hoverTimeout = setTimeout(() => {
                                    layer.bindPopup(name, { className: 'hover-tooltip' }).openPopup();
                                }, 300);
                            });
                            layer.on('mouseout', () => {
                                clearTimeout(hoverTimeout);
                                layer.closePopup();
                            });
                            layer.on('click', () => {
                                window.location.href = `${baseUrl}${permalinkBase}${slug}`;
                            });
                        }
                    }).addTo(flatMap).bringToFront();
                    flatMap.fitBounds(L.geoJSON(data).getBounds());
                })
                .catch(error => {
                    console.error('Error loading country GeoJSON:', error);
                    showError('Failed to load country map data. Please try refreshing.');
                });

            fetch(usStatesGeoJsonUrl)
                .then(response => response.json())
                .then(data => {
                    if (!data.features) throw new Error('Invalid GeoJSON: No features found');
                    data.features = data.features.filter(feature => 
                        isValidGeometry(feature.geometry) && displayedLocations.includes(feature.properties.name)
                    );
                    if (data.features.length === 0) {
                        showError('No matching US states found in GeoJSON.');
                        return;
                    }
                    L.geoJSON(data, {
                        style: {
                            fillColor: usStateFillColor,
                            weight: 1,
                            opacity: 1,
                            color: usStateBorderColor,
                            fillOpacity: usStateFillOpacity
                        },
                        onEachFeature: (feature, layer) => {
                            const name = feature.properties.name || 'Unknown';
                            const slug = name.toLowerCase().replace(/\s+/g, '-').trim();
                            let hoverTimeout;
                            layer.on('mouseover', () => {
                                hoverTimeout = setTimeout(() => {
                                    layer.bindPopup(name, { className: 'hover-tooltip' }).openPopup();
                                }, 300);
                            });
                            layer.on('mouseout', () => {
                                clearTimeout(hoverTimeout);
                                layer.closePopup();
                            });
                            layer.on('click', () => {
                                window.location.href = `${baseUrl}${permalinkBase}${slug}`;
                            });
                        }
                    }).addTo(flatMap);
                })
                .catch(error => {
                    console.error('Error loading US states GeoJSON:', error);
                    showError('Failed to load US states map data. Please try refreshing.');
                });

            const globe = Globe()
                .height(500)
                .globeImageUrl('https://unpkg.com/three-globe/example/img/earth-night.jpg')
                .backgroundColor(globeBackgroundColor)
                .polygonsData([])
                .polygonCapColor(() => globeCapColor)
                .polygonSideColor(() => globeSideColor)
                .polygonStrokeColor(() => globeStrokeColor)
                .polygonLabel(({ properties }) => `<b>${properties.name || 'Unknown'}</b>`)
                .onPolygonClick(({ properties }) => {
                    const name = properties.name || 'unknown';
                    const slug = name.toLowerCase().replace(/\s+/g, '-').trim();
                    window.location.href = `${baseUrl}${permalinkBase}${slug}`;
                })
                (document.getElementById('globe'));

            globe.pointOfView({ lat: 39, lng: -76, altitude: 2.5 }, 0);

            fetch(countryGeoJsonUrl)
                .then(response => response.json())
                .then(data => {
                    if (!data.features) throw new Error('Invalid GeoJSON: No features found');
                    data.features = data.features.filter(feature => 
                        isValidGeometry(feature.geometry) && displayedLocations.includes(feature.properties.name)
                    );
                    if (data.features.length === 0) {
                        showError('No matching countries found for globe.');
                        return;
                    }
                    globe.polygonsData(data.features);
                })
                .catch(error => {
                    console.error('Error loading country GeoJSON for globe:', error);
                    showError('Failed to load globe data. Please try refreshing.');
                });

            const toggleBtn = document.getElementById('toggle-btn');
            const flatMapDiv = document.getElementById('flat-map');
            const globeDiv = document.getElementById('globe');

            toggleBtn.addEventListener('change', () => {
                if (toggleBtn.checked) {
                    flatMapDiv.classList.remove('active');
                    globeDiv.classList.add('active');
                } else {
                    globeDiv.classList.remove('active');
                    flatMapDiv.classList.add('active');
                    flatMap.invalidateSize();
                }
            });
        });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('travel_map', 'travel_map_widget_shortcode');

// Admin settings page
function travel_map_widget_admin_menu() {
    add_options_page(
        'Travel Map Settings',
        'Travel Map',
        'manage_options',
        'travel-map-settings',
        'travel_map_widget_settings_page'
    );
}
add_action('admin_menu', 'travel_map_widget_admin_menu');

// Settings page callback
function travel_map_widget_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['submit']) && check_admin_referer('travel_map_settings_save', 'travel_map_nonce')) {
        $selected_locations = isset($_POST['displayed_locations']) ? array_map('sanitize_text_field', (array)$_POST['displayed_locations']) : [];
        update_option('travel_map_displayed_locations', implode(',', $selected_locations));
        $permalink_base = isset($_POST['permalink_base']) ? sanitize_text_field(trim($_POST['permalink_base'], '/')) : 'wp/location';
        update_option('travel_map_permalink_base', '/' . $permalink_base . '/');
        update_option('travel_map_country_fill_color', sanitize_hex_color($_POST['country_fill_color']) ?: '#9000b4');
        update_option('travel_map_country_border_color', sanitize_hex_color($_POST['country_border_color']) ?: '#ffffff');
        update_option('travel_map_country_fill_opacity', floatval($_POST['country_fill_opacity']) >= 0 && floatval($_POST['country_fill_opacity']) <= 1 ? floatval($_POST['country_fill_opacity']) : 0.3);
        update_option('travel_map_us_state_fill_color', sanitize_hex_color($_POST['us_state_fill_color']) ?: '#00aaff');
        update_option('travel_map_us_state_border_color', sanitize_hex_color($_POST['us_state_border_color']) ?: '#ffffff');
        update_option('travel_map_us_state_fill_opacity', floatval($_POST['us_state_fill_opacity']) >= 0 && floatval($_POST['us_state_fill_opacity']) <= 1 ? floatval($_POST['us_state_fill_opacity']) : 0.3);
        $cap_color = sanitize_hex_color($_POST['globe_cap_color']) ?: '#9100b4';
        $cap_opacity = floatval($_POST['globe_cap_opacity']) >= 0 && floatval($_POST['globe_cap_opacity']) <= 1 ? floatval($_POST['globe_cap_opacity']) : 0.3;
        update_option('travel_map_globe_cap_color', "rgba(" . hexToRgb($cap_color) . ", $cap_opacity)");
        $side_color = sanitize_hex_color($_POST['globe_side_color']) ?: '#006400';
        $side_opacity = floatval($_POST['globe_side_opacity']) >= 0 && floatval($_POST['globe_side_opacity']) <= 1 ? floatval($_POST['globe_side_opacity']) : 0.15;
        update_option('travel_map_globe_side_color', "rgba(" . hexToRgb($side_color) . ", $side_opacity)");
        update_option('travel_map_globe_cap_opacity', $cap_opacity);
        update_option('travel_map_globe_side_opacity', $side_opacity);
        update_option('travel_map_globe_stroke_color', sanitize_hex_color($_POST['globe_stroke_color']) ?: '#111111');
        update_option('travel_map_globe_background_color', sanitize_hex_color($_POST['globe_background_color']) ?: '#000000');
    }

    $displayed_locations = get_option('travel_map_displayed_locations', '');
    $selected_locations = !empty($displayed_locations) ? array_map('trim', explode(',', $displayed_locations)) : [];
    $permalink_base = get_option('travel_map_permalink_base', '/wp/location/');
    $country_fill_color = get_option('travel_map_country_fill_color', '#9000b4');
    $country_border_color = get_option('travel_map_country_border_color', '#ffffff');
    $country_fill_opacity = get_option('travel_map_country_fill_opacity', '0.3');
    $us_state_fill_color = get_option('travel_map_us_state_fill_color', '#00aaff');
    $us_state_border_color = get_option('travel_map_us_state_border_color', '#ffffff');
    $us_state_fill_opacity = get_option('travel_map_us_state_fill_opacity', '0.3');
    $globe_cap_color = get_option('travel_map_globe_cap_color', 'rgba(145, 0, 180, 0.3)');
    $globe_cap_opacity = get_option('travel_map_globe_cap_opacity', '0.3');
    $globe_side_color = get_option('travel_map_globe_side_color', 'rgba(0, 100, 0, 0.15)');
    $globe_side_opacity = get_option('travel_map_globe_side_opacity', '0.15');
    $globe_stroke_color = get_option('travel_map_globe_stroke_color', '#111111');
    $globe_background_color = get_option('travel_map_globe_background_color', '#000000');

    $countries = [
        'Afghanistan', 'Albania', 'Algeria', 'Andorra', 'Angola', 'Antigua and Barbuda', 'Argentina', 'Armenia', 
        'Australia', 'Austria', 'Azerbaijan', 'Bahamas', 'Bahrain', 'Bangladesh', 'Barbados', 'Belarus', 'Belgium', 
        'Belize', 'Benin', 'Bhutan', 'Bolivia', 'Bosnia and Herzegovina', 'Botswana', 'Brazil', 'Brunei', 
        'Bulgaria', 'Burkina Faso', 'Burundi', 'Cambodia', 'Cameroon', 'Canada', 'Cape Verde', 
        'Central African Republic', 'Chad', 'Chile', 'China', 'Colombia', 'Comoros', 'Congo', 'Costa Rica', 
        'Croatia', 'Cuba', 'Cyprus', 'Czech Republic', 'Democratic Republic of the Congo', 'Denmark', 
        'Djibouti', 'Dominica', 'Dominican Republic', 'East Timor', 'Ecuador', 'Egypt', 'El Salvador', 
        'Equatorial Guinea', 'Eritrea', 'Estonia', 'Eswatini', 'Ethiopia', 'Fiji', 'Finland', 'France', 
        'Gabon', 'Gambia', 'Georgia', 'Germany', 'Ghana', 'Greece', 'Grenada', 'Guatemala', 'Guinea', 
        'Guinea-Bissau', 'Guyana', 'Haiti', 'Honduras', 'Hungary', 'Iceland', 'India', 'Indonesia', 
        'Iran', 'Iraq', 'Ireland', 'Israel', 'Italy', 'Ivory Coast', 'Jamaica', 'Japan', 'Jordan', 
        'Kazakhstan', 'Kenya', 'Kiribati', 'Kuwait', 'Kyrgyzstan', 'Laos', 'Latvia', 'Lebanon', 
        'Lesotho', 'Liberia', 'Libya', 'Liechtenstein', 'Lithuania', 'Luxembourg', 'Madagascar', 
        'Malawi', 'Malaysia', 'Maldives', 'Mali', 'Malta', 'Marshall Islands', 'Mauritania', 'Mauritius', 
        'Mexico', 'Micronesia', 'Moldova', 'Monaco', 'Mongolia', 'Montenegro', 'Morocco', 'Mozambique', 
        'Myanmar', 'Namibia', 'Nauru', 'Nepal', 'Netherlands', 'New Zealand', 'Nicaragua', 'Niger', 
        'Nigeria', 'North Korea', 'North Macedonia', 'Norway', 'Oman', 'Pakistan', 'Palau', 'Panama', 
        'Papua New Guinea', 'Paraguay', 'Peru', 'Philippines', 'Poland', 'Portugal', 'Qatar', 
        'Romania', 'Russia', 'Rwanda', 'Saint Kitts and Nevis', 'Saint Lucia', 'Saint Vincent and the Grenadines', 
        'Samoa', 'San Marino', 'Sao Tome and Principe', 'Saudi Arabia', 'Senegal', 'Serbia', 'Seychelles', 
        'Sierra Leone', 'Singapore', 'Slovakia', 'Slovenia', 'Solomon Islands', 'Somalia', 'South Africa', 
        'South Korea', 'South Sudan', 'Spain', 'Sri Lanka', 'Sudan', 'Suriname', 'Sweden', 'Switzerland', 
        'Syria', 'Taiwan', 'Tajikistan', 'Tanzania', 'Thailand', 'Togo', 'Tonga', 'Trinidad and Tobago', 
        'Tunisia', 'Turkey', 'Turkmenistan', 'Tuvalu', 'Uganda', 'Ukraine', 'United Arab Emirates', 
        'United Kingdom', 'United States of America', 'Uruguay', 'Uzbekistan', 'Vanuatu', 'Vatican City', 
        'Venezuela', 'Vietnam', 'Yemen', 'Zambia', 'Zimbabwe'
    ];
    $us_states = [
        'Alabama', 'Alaska', 'Arizona', 'Arkansas', 'California', 'Colorado', 'Connecticut', 'Delaware',
        'Florida', 'Georgia', 'Hawaii', 'Idaho', 'Illinois', 'Indiana', 'Iowa', 'Kansas', 'Kentucky',
        'Louisiana', 'Maine', 'Maryland', 'Massachusetts', 'Michigan', 'Minnesota', 'Mississippi',
        'Missouri', 'Montana', 'Nebraska', 'Nevada', 'New Hampshire', 'New Jersey', 'New Mexico',
        'New York', 'North Carolina', 'North Dakota', 'Ohio', 'Oklahoma', 'Oregon', 'Pennsylvania',
        'Rhode Island', 'South Carolina', 'South Dakota', 'Tennessee', 'Texas', 'Utah', 'Vermont',
        'Virginia', 'Washington', 'West Virginia', 'Wisconsin', 'Wyoming'
    ];
    ?>
    <div class="wrap">
        <h1>Travel Map Settings</h1>
        <form method="post">
            <?php wp_nonce_field('travel_map_settings_save', 'travel_map_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="permalink_base">Permalink Base:</label></th>
                    <td>
                        <input type="text" id="permalink_base" name="permalink_base" value="<?php echo esc_attr($permalink_base); ?>" style="width: 300px;">
                        <p class="description">Enter the base URL for location pages (e.g., /wp/location/, /travel/). Must match your WordPress permalink structure. Default is /wp/location/.</p>
                    </td>
                </tr>
            </table>

            <h2>Displayed Locations</h2>
            <p>Select the countries and US states to display on the map.</p>
            <label><input type="checkbox" id="select-all-countries" onclick="toggleCheckboxes('countries', this.checked)"> Select All Countries</label><br>
            <div id="countries" style="max-height: 300px; overflow-y: auto; margin-bottom: 20px;">
                <?php foreach ($countries as $country): ?>
                    <label style="display: block;">
                        <input type="checkbox" name="displayed_locations[]" value="<?php echo esc_attr($country); ?>" 
                            <?php echo in_array($country, $selected_locations) ? 'checked' : ''; ?>>
                        <?php echo esc_html($country); ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <label><input type="checkbox" id="select-all-states" onclick="toggleCheckboxes('us-states', this.checked)"> Select All US States</label><br>
            <div id="us-states" style="max-height: 300px; overflow-y: auto;">
                <?php foreach ($us_states as $state): ?>
                    <label style="display: block;">
                        <input type="checkbox" name="displayed_locations[]" value="<?php echo esc_attr($state); ?>" 
                            <?php echo in_array($state, $selected_locations) ? 'checked' : ''; ?>>
                        <?php echo esc_html($state); ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <h2>Color Settings</h2>
            <table class="form-table">
                <tr>
                    <th colspan="2"><h3>Countries</h3></th>
                </tr>
                <tr>
                    <th><label for="country_fill_color">Fill Color:</label></th>
                    <td><input type="color" id="country_fill_color" name="country_fill_color" value="<?php echo esc_attr($country_fill_color); ?>"></td>
                </tr>
                <tr>
                    <th><label for="country_border_color">Border Color:</label></th>
                    <td><input type="color" id="country_border_color" name="country_border_color" value="<?php echo esc_attr($country_border_color); ?>"></td>
                </tr>
                <tr>
                    <th><label for="country_fill_opacity">Fill Opacity (0-1):</label></th>
                    <td><input type="number" id="country_fill_opacity" name="country_fill_opacity" value="<?php echo esc_attr($country_fill_opacity); ?>" step="0.1" min="0" max="1"></td>
                </tr>
                <tr>
                    <th colspan="2"><h3>US States</h3></th>
                </tr>
                <tr>
                    <th><label for="us_state_fill_color">Fill Color:</label></th>
                    <td><input type="color" id="us_state_fill_color" name="us_state_fill_color" value="<?php echo esc_attr($us_state_fill_color); ?>"></td>
                </tr>
                <tr>
                    <th><label for="us_state_border_color">Border Color:</label></th>
                    <td><input type="color" id="us_state_border_color" name="us_state_border_color" value="<?php echo esc_attr($us_state_border_color); ?>"></td>
                </tr>
                <tr>
                    <th><label for="us_state_fill_opacity">Fill Opacity (0-1):</label></th>
                    <td><input type="number" id="us_state_fill_opacity" name="us_state_fill_opacity" value="<?php echo esc_attr($us_state_fill_opacity); ?>" step="0.1" min="0" max="1"></td>
                </tr>
                <tr>
                    <th colspan="2"><h3>Globe</h3></th>
                </tr>
                <tr>
                    <th><label for="globe_cap_color">Polygon Cap Color:</label></th>
                    <td>
                        <input type="color" id="globe_cap_color" name="globe_cap_color" value="<?php echo esc_attr(hexFromRgba($globe_cap_color)); ?>">
                        <input type="number" id="globe_cap_opacity" name="globe_cap_opacity" value="<?php echo esc_attr($globe_cap_opacity); ?>" step="0.1" min="0" max="1" style="margin-left: 10px;">
                    </td>
                </tr>
                <tr>
                    <th><label for="globe_side_color">Polygon Side Color:</label></th>
                    <td>
                        <input type="color" id="globe_side_color" name="globe_side_color" value="<?php echo esc_attr(hexFromRgba($globe_side_color)); ?>">
                        <input type="number" id="globe_side_opacity" name="globe_side_opacity" value="<?php echo esc_attr($globe_side_opacity); ?>" step="0.1" min="0" max="1" style="margin-left: 10px;">
                    </td>
                </tr>
                <tr>
                    <th><label for="globe_stroke_color">Polygon Stroke Color:</label></th>
                    <td><input type="color" id="globe_stroke_color" name="globe_stroke_color" value="<?php echo esc_attr($globe_stroke_color); ?>"></td>
                </tr>
                <tr>
                    <th><label for="globe_background_color">Background Color:</label></th>
                    <td><input type="color" id="globe_background_color" name="globe_background_color" value="<?php echo esc_attr($globe_background_color); ?>"></td>
                </tr>
            </table>

            <input type="submit" name="submit" value="Save Changes" class="button-primary" style="margin-top: 20px;">
        </form>
        <script>
            function toggleCheckboxes(containerId, checked) {
                const checkboxes = document.querySelectorAll(`#${containerId} input[type="checkbox"]`);
                checkboxes.forEach(checkbox => checkbox.checked = checked);
            }

            // Helper function to convert hex to RGB
            function hexToRgb(hex) {
                hex = hex.replace(/^#/, '');
                const bigint = parseInt(hex, 16);
                const r = (bigint >> 16) & 255;
                const g = (bigint >> 8) & 255;
                const b = bigint & 255;
                return `${r}, ${g}, ${b}`;
            }

            // Helper function to extract hex from rgba
            function hexFromRgba(rgba) {
                const match = rgba.match(/^rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*[\d.]+)?\)$/);
                if (match) {
                    const r = parseInt(match[1]).toString(16).padStart(2, '0');
                    const g = parseInt(match[2]).toString(16).padStart(2, '0');
                    const b = parseInt(match[3]).toString(16).padStart(2, '0');
                    return `#${r}${g}${b}`;
                }
                return '#9100b4'; // Default hex if parsing fails
            }
        </script>
    </div>
    <?php
}

// Register settings
function travel_map_widget_register_settings() {
    register_setting('travel_map_settings', 'travel_map_displayed_locations', [
        'sanitize_callback' => function($value) {
            return is_array($value) ? implode(',', array_map('sanitize_text_field', $value)) : sanitize_text_field($value);
        }
    ]);
    register_setting('travel_map_settings', 'travel_map_permalink_base', [
        'sanitize_callback' => function($value) {
            $value = trim($value, '/');
            return '/' . sanitize_text_field($value) . '/';
        }
    ]);
    register_setting('travel_map_settings', 'travel_map_country_fill_color', [
        'sanitize_callback' => 'sanitize_hex_color'
    ]);
    register_setting('travel_map_settings', 'travel_map_country_border_color', [
        'sanitize_callback' => 'sanitize_hex_color'
    ]);
    register_setting('travel_map_settings', 'travel_map_country_fill_opacity', [
        'sanitize_callback' => function($value) {
            $value = floatval($value);
            return ($value >= 0 && $value <= 1) ? $value : 0.3;
        }
    ]);
    register_setting('travel_map_settings', 'travel_map_us_state_fill_color', [
        'sanitize_callback' => 'sanitize_hex_color'
    ]);
    register_setting('travel_map_settings', 'travel_map_us_state_border_color', [
        'sanitize_callback' => 'sanitize_hex_color'
    ]);
    register_setting('travel_map_settings', 'travel_map_us_state_fill_opacity', [
        'sanitize_callback' => function($value) {
            $value = floatval($value);
            return ($value >= 0 && $value <= 1) ? $value : 0.3;
        }
    ]);
    register_setting('travel_map_settings', 'travel_map_globe_cap_color', [
        'sanitize_callback' => 'sanitize_text_field'
    ]);
    register_setting('travel_map_settings', 'travel_map_globe_cap_opacity', [
        'sanitize_callback' => function($value) {
            $value = floatval($value);
            return ($value >= 0 && $value <= 1) ? $value : 0.3;
        }
    ]);
    register_setting('travel_map_settings', 'travel_map_globe_side_color', [
        'sanitize_callback' => 'sanitize_text_field'
    ]);
    register_setting('travel_map_settings', 'travel_map_globe_side_opacity', [
        'sanitize_callback' => function($value) {
            $value = floatval($value);
            return ($value >= 0 && $value <= 1) ? $value : 0.15;
        }
    ]);
    register_setting('travel_map_settings', 'travel_map_globe_stroke_color', [
        'sanitize_callback' => 'sanitize_hex_color'
    ]);
    register_setting('travel_map_settings', 'travel_map_globe_background_color', [
        'sanitize_callback' => 'sanitize_hex_color'
    ]);
}
add_action('admin_init', 'travel_map_widget_register_settings');
?>
