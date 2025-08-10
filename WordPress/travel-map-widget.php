<?php
/**
 * Plugin Name: Travel Map Widget
 * Plugin URI: https://github.com/TonyCicero/Travel-Map-Widget
 * Description: An interactive travel map widget with toggle between flat map and globe, managed locations via checkboxes, and configurable permalinks.
 * Version: 1.0.0
 * Author: Tony Cicero
 * Author URI: https://github.com/TonyCicero
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: travel-map-widget
 */


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
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
    wp_enqueue_style('travel-map-widget-css', plugin_dir_url(__FILE__) . 'travel-map-widget.css', [], '1.3.0');

    // Pass data to JS
    $displayed_locations = get_option('travel_map_displayed_locations', '');
    $locations_array = !empty($displayed_locations) ? array_map('trim', explode(',', $displayed_locations)) : [
        'California', 'Florida', 'Maryland', 'Massachusetts', 'New York', 'Nevada', 'Pennsylvania', 'Virginia',
        'Albania', 'Austria', 'Belgium', 'Cambodia', 'Canada', 'Czech Republic', 'Denmark', 'Dominican Republic',
        'France', 'Germany', 'Greece', 'Ireland', 'Japan', 'Laos', 'Netherlands', 'Poland', 'Slovakia', 'Sweden',
        'Switzerland', 'Thailand', 'United Kingdom', 'United States of America', 'Vietnam'
    ];
    $permalink_base = get_option('travel_map_permalink_base', '/wp/location/');
    wp_localize_script('leaflet-js', 'travelMapVars', [
        'baseUrl' => home_url(),
        'displayedLocations' => $locations_array,
        'permalinkBase' => $permalink_base
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
            // Check if travelMapVars is defined
            if (typeof travelMapVars === 'undefined') {
                console.error('travelMapVars is not defined. Ensure scripts are loaded correctly.');
                document.getElementById('error-message').style.display = 'block';
                document.getElementById('error-message').textContent = 'Error: Map configuration not loaded. Please refresh the page.';
                return;
            }

            // Use localized data from WP
            const baseUrl = travelMapVars.baseUrl;
            const displayedLocations = travelMapVars.displayedLocations;
            const permalinkBase = travelMapVars.permalinkBase;

            // GeoJSON URLs
            const countryGeoJsonUrl = 'https://raw.githubusercontent.com/TonyCicero/Map-Widget/refs/heads/main/world.geo.json';
            const usStatesGeoJsonUrl = 'https://raw.githubusercontent.com/TonyCicero/Map-Widget/refs/heads/main/us_states.geo.json';

            // Error display function
            const showError = (message) => {
                const errorDiv = document.getElementById('error-message');
                errorDiv.style.display = 'block';
                errorDiv.textContent = message;
            };

            // Validate GeoJSON geometry
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

            // Initialize Flat Map (Leaflet)
            const flatMap = L.map('flat-map').setView([20, 0], 2);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                maxZoom: 18,
                minZoom: 2
            }).addTo(flatMap);

            // Load and render countries for flat map
            fetch(countryGeoJsonUrl)
                .then(response => {
                    if (!response.ok) throw new Error(`HTTP ${response.status}: Failed to load countries GeoJSON`);
                    return response.json();
                })
                .then(data => {
                    if (!data.features) throw new Error('Invalid GeoJSON: No features found');
                    // Filter by displayedLocations and valid geometries
                    data.features = data.features.filter(feature => 
                        isValidGeometry(feature.geometry) && 
                        displayedLocations.includes(feature.properties.name)
                    );
                    if (data.features.length === 0) {
                        showError('No matching countries found in GeoJSON.');
                        return;
                    }
                    const geoLayer = L.geoJSON(data, {
                        style: {
                            fillColor: '#9000b4',
                            weight: 2,
                            opacity: 1,
                            color: 'white',
                            fillOpacity: 0.3
                        },
                        onEachFeature: (feature, layer) => {
                            const name = feature.properties.name || 'Unknown';
                            const slug = name.toLowerCase().replace(/\s+/g, '-').trim();
                            layer.bindPopup(name);
                            layer.on('click', () => {
                                window.location.href = `${baseUrl}${permalinkBase}${slug}`;
                            });
                        }
                    }).addTo(flatMap);
                    flatMap.fitBounds(geoLayer.getBounds());
                })
                .catch(error => {
                    console.error('Error loading country GeoJSON:', error);
                    showError('Failed to load country map data. Please try refreshing.');
                });

            // Load and render US states for flat map
            fetch(usStatesGeoJsonUrl)
                .then(response => {
                    if (!response.ok) throw new Error(`HTTP ${response.status}: Failed to load US states GeoJSON`);
                    return response.json();
                })
                .then(data => {
                    if (!data.features) throw new Error('Invalid GeoJSON: No features found');
                    // Filter by displayedLocations and valid geometries
                    data.features = data.features.filter(feature => 
                        isValidGeometry(feature.geometry) && 
                        displayedLocations.includes(feature.properties.name)
                    );
                    if (data.features.length === 0) {
                        showError('No matching US states found in GeoJSON.');
                        return;
                    }
                    L.geoJSON(data, {
                        style: {
                            fillColor: '#00aaff',
                            weight: 1,
                            opacity: 1,
                            color: 'white',
                            fillOpacity: 0.3
                        },
                        onEachFeature: (feature, layer) => {
                            const name = feature.properties.NAME || 'Unknown';
                            const slug = name.toLowerCase().replace(/\s+/g, '-').trim();
                            layer.bindPopup(name);
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

            // Initialize Globe (Globe.GL)
            const globe = Globe()
				.height(500)
                .globeImageUrl('https://unpkg.com/three-globe/example/img/earth-night.jpg')
                .backgroundColor('#000000')
                .polygonsData([])
                .polygonCapColor(() => 'rgba(145, 0, 180, 0.3)')
                .polygonSideColor(() => 'rgba(0, 100, 0, 0.15)')
                .polygonStrokeColor(() => '#111')
                .polygonLabel(({ properties }) => `<b>${properties.name || 'Unknown'}</b>`)
                .onPolygonClick(({ properties }) => {
                    const name = properties.name || 'unknown';
                    const slug = name.toLowerCase().replace(/\s+/g, '-').trim();
                    window.location.href = `${baseUrl}${permalinkBase}${slug}`;
                })
                (document.getElementById('globe'));
				
				globe.pointOfView({ lat: 39, lng: -76, altitude: 2.5 }, 0)

            // Load GeoJSON for globe
            fetch(countryGeoJsonUrl)
                .then(response => {
                    if (!response.ok) throw new Error(`HTTP ${response.status}: Failed to load countries GeoJSON for globe`);
                    return response.json();
                })
                .then(data => {
                    if (!data.features) throw new Error('Invalid GeoJSON: No features found');
                    // Filter by displayedLocations and valid geometries
                    data.features = data.features.filter(feature => 
                        isValidGeometry(feature.geometry) && 
                        displayedLocations.includes(feature.properties.name)
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

            // Toggle between flat map and globe
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

    // Save settings if submitted
    if (isset($_POST['submit']) && check_admin_referer('travel_map_settings_save', 'travel_map_nonce')) {
        $selected_locations = isset($_POST['displayed_locations']) ? array_map('sanitize_text_field', (array)$_POST['displayed_locations']) : [];
        update_option('travel_map_displayed_locations', implode(',', $selected_locations));
        $permalink_base = isset($_POST['permalink_base']) ? sanitize_text_field(trim($_POST['permalink_base'], '/')) : 'wp/location';
        update_option('travel_map_permalink_base', '/' . $permalink_base . '/');
    }

    $displayed_locations = get_option('travel_map_displayed_locations', '');
    $selected_locations = !empty($displayed_locations) ? array_map('trim', explode(',', $displayed_locations)) : [];
    $permalink_base = get_option('travel_map_permalink_base', '/wp/location/');

    // Hardcoded list of countries and US states
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
            <h2>Permalink Settings</h2>
            <label for="permalink_base">Permalink Base:</label><br>
            <input type="text" id="permalink_base" name="permalink_base" value="<?php echo esc_attr($permalink_base); ?>" style="width: 300px;">
            <p class="description">Enter the base URL for location pages (e.g., /wp/location/, /travel/). Must match your WordPress permalink structure. Default is /wp/location/.</p>
            
            <h2>Displayed Locations</h2>
            <p>Select the countries and US states to display on the map.</p>
            <label><input type="checkbox" id="select-all-countries" onclick="toggleCheckboxes('countries', this.checked)"> Select All Countries</label><br>
            <h3>Countries</h3>
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
            <h3>US States</h3>
            <div id="us-states" style="max-height: 300px; overflow-y: auto;">
                <?php foreach ($us_states as $state): ?>
                    <label style="display: block;">
                        <input type="checkbox" name="displayed_locations[]" value="<?php echo esc_attr($state); ?>" 
                            <?php echo in_array($state, $selected_locations) ? 'checked' : ''; ?>>
                        <?php echo esc_html($state); ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <input type="submit" name="submit" value="Save Changes" class="button-primary" style="margin-top: 20px;">
        </form>
        <script>
            function toggleCheckboxes(containerId, checked) {
                const checkboxes = document.querySelectorAll(`#${containerId} input[type="checkbox"]`);
                checkboxes.forEach(checkbox => checkbox.checked = checked);
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
}
add_action('admin_init', 'travel_map_widget_register_settings');
?>
