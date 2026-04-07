<?php
/**
 * MTTI PWA Support
 * Converts the MTTI Student Portal into a Progressive Web App
 * 
 * @package MTTI_MIS
 * @since 4.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MTTI_MIS_PWA {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('wp_head', array($this, 'add_pwa_meta_tags'), 1);
        add_action('wp_footer', array($this, 'register_service_worker'));
        add_action('init', array($this, 'serve_pwa_files'));
        add_action('init', array($this, 'create_student_portal_page'));
    }
    
    /**
     * Get icon URL from plugin folder
     */
    private function get_icon_url($size) {
        return MTTI_MIS_PLUGIN_URL . "assets/icons/icon-{$size}x{$size}.png";
    }
    
    /**
     * Add PWA meta tags to head
     */
    public function add_pwa_meta_tags() {
        $theme_color = '#2E7D32';
        ?>
        <!-- MTTI PWA Meta Tags -->
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
        <link rel="manifest" href="<?php echo esc_url(home_url('/mtti-manifest.json')); ?>">
        <meta name="theme-color" content="<?php echo esc_attr($theme_color); ?>">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="MTTI Portal">
        <meta name="application-name" content="MTTI Portal">
        <meta name="msapplication-TileColor" content="<?php echo esc_attr($theme_color); ?>">
        <meta name="msapplication-TileImage" content="<?php echo esc_url($this->get_icon_url(144)); ?>">
        
        <!-- Apple Touch Icons -->
        <link rel="apple-touch-icon" href="<?php echo esc_url($this->get_icon_url(192)); ?>">
        <link rel="apple-touch-icon" sizes="152x152" href="<?php echo esc_url($this->get_icon_url(152)); ?>">
        <link rel="apple-touch-icon" sizes="180x180" href="<?php echo esc_url($this->get_icon_url(192)); ?>">
        <link rel="apple-touch-icon" sizes="167x167" href="<?php echo esc_url($this->get_icon_url(192)); ?>">
        
        <!-- Favicon -->
        <link rel="icon" type="image/png" sizes="32x32" href="<?php echo esc_url($this->get_icon_url(96)); ?>">
        <link rel="icon" type="image/png" sizes="16x16" href="<?php echo esc_url($this->get_icon_url(72)); ?>">
        <?php
    }
    
    /**
     * Register service worker
     */
    public function register_service_worker() {
        $sw_url = home_url('/mtti-sw.js');
        ?>
        <script>
        // Register MTTI Service Worker
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('<?php echo esc_url($sw_url); ?>', { scope: '/' })
                    .then(function(registration) {
                        console.log('MTTI PWA: Service Worker registered successfully');
                        
                        // Check for updates
                        registration.addEventListener('updatefound', function() {
                            const newWorker = registration.installing;
                            newWorker.addEventListener('statechange', function() {
                                if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                                    // New content available - show update notification
                                    if (confirm('New version of MTTI Portal available! Reload to update?')) {
                                        window.location.reload();
                                    }
                                }
                            });
                        });
                    })
                    .catch(function(error) {
                        console.log('MTTI PWA: Service Worker registration failed:', error);
                    });
            });
        }
        
        // Handle install prompt
        let deferredPrompt;
        window.addEventListener('beforeinstallprompt', function(e) {
            e.preventDefault();
            deferredPrompt = e;
            
            // Show install banner on portal pages
            showInstallBanner();
        });
        
        function showInstallBanner() {
            // Only show on portal pages
            if (!document.querySelector('.mtti-portal-wrapper')) return;
            
            // Check if already dismissed
            if (localStorage.getItem('mtti-pwa-dismissed')) return;
            
            // Create install banner
            const banner = document.createElement('div');
            banner.id = 'mtti-install-banner';
            banner.innerHTML = `
                <style>
                    #mtti-install-banner {
                        position: fixed;
                        bottom: 0;
                        left: 0;
                        right: 0;
                        background: linear-gradient(135deg, #2E7D32, #1B5E20);
                        color: white;
                        padding: 15px 20px;
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        z-index: 9999;
                        box-shadow: 0 -2px 10px rgba(0,0,0,0.2);
                        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    }
                    #mtti-install-banner .banner-text {
                        display: flex;
                        align-items: center;
                        gap: 10px;
                    }
                    #mtti-install-banner .banner-icon {
                        font-size: 24px;
                    }
                    #mtti-install-banner .banner-buttons {
                        display: flex;
                        gap: 10px;
                    }
                    #mtti-install-banner button {
                        padding: 10px 20px;
                        border: none;
                        border-radius: 6px;
                        cursor: pointer;
                        font-weight: 600;
                        font-size: 14px;
                    }
                    #mtti-install-banner .install-btn {
                        background: white;
                        color: #2E7D32;
                    }
                    #mtti-install-banner .dismiss-btn {
                        background: transparent;
                        color: white;
                        border: 1px solid rgba(255,255,255,0.5);
                    }
                    @media (max-width: 600px) {
                        #mtti-install-banner {
                            flex-direction: column;
                            gap: 10px;
                            text-align: center;
                        }
                    }
                </style>
                <div class="banner-text">
                    <span class="banner-icon">📱</span>
                    <span><strong>Install MTTI Portal</strong> - Access your courses offline!</span>
                </div>
                <div class="banner-buttons">
                    <button class="install-btn" onclick="installPWA()">Install App</button>
                    <button class="dismiss-btn" onclick="dismissBanner()">Not Now</button>
                </div>
            `;
            document.body.appendChild(banner);
        }
        
        window.installPWA = function() {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then(function(choiceResult) {
                    if (choiceResult.outcome === 'accepted') {
                        console.log('MTTI PWA: User accepted install');
                    }
                    deferredPrompt = null;
                    document.getElementById('mtti-install-banner').remove();
                });
            }
        };
        
        window.dismissBanner = function() {
            localStorage.setItem('mtti-pwa-dismissed', 'true');
            document.getElementById('mtti-install-banner').remove();
        };
        
        // Detect if running as PWA
        if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true) {
            document.body.classList.add('mtti-pwa-standalone');
        }
        </script>
        <?php
    }
    
    /**
     * Serve PWA files (manifest.json and service-worker.js)
     */
    public function serve_pwa_files() {
        $request_uri = $_SERVER['REQUEST_URI'];
        $request_uri = strtok($request_uri, '?'); // Remove query string
        
        // Serve manifest.json
        if ($request_uri === '/mtti-manifest.json') {
            header('Content-Type: application/json');
            header('Cache-Control: public, max-age=86400');
            
            $portal_url = $this->get_portal_url();
            
            $manifest = array(
                'name' => 'MTTI Student Portal',
                'short_name' => 'MTTI',
                'description' => 'Masomotele Technical Training Institute - Student Portal. Start Learning, Start Earning.',
                'start_url' => $portal_url,
                'scope' => '/',
                'display' => 'standalone',
                'background_color' => '#ffffff',
                'theme_color' => '#2E7D32',
                'orientation' => 'portrait-primary',
                'categories' => array('education', 'productivity'),
                'lang' => 'en',
                'icons' => $this->get_icons_array(),
                'shortcuts' => array(
                    array(
                        'name' => 'My Courses',
                        'short_name' => 'Courses',
                        'url' => $portal_url . '?portal_tab=courses',
                        'icons' => array(array('src' => $this->get_icon_url(96), 'sizes' => '96x96'))
                    ),
                    array(
                        'name' => 'My Results',
                        'short_name' => 'Results',
                        'url' => $portal_url . '?portal_tab=results',
                        'icons' => array(array('src' => $this->get_icon_url(96), 'sizes' => '96x96'))
                    ),
                    array(
                        'name' => 'Payments',
                        'short_name' => 'Pay',
                        'url' => $portal_url . '?portal_tab=payments',
                        'icons' => array(array('src' => $this->get_icon_url(96), 'sizes' => '96x96'))
                    ),
                    array(
                        'name' => 'Notices',
                        'short_name' => 'News',
                        'url' => $portal_url . '?portal_tab=notices',
                        'icons' => array(array('src' => $this->get_icon_url(96), 'sizes' => '96x96'))
                    )
                )
            );
            
            echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }
        
        // Serve service-worker.js
        if ($request_uri === '/mtti-sw.js') {
            header('Content-Type: application/javascript');
            header('Cache-Control: no-cache');
            header('Service-Worker-Allowed: /');
            
            $this->output_service_worker();
            exit;
        }
        
        // Serve offline.html
        if ($request_uri === '/mtti-offline.html') {
            header('Content-Type: text/html');
            $offline_file = MTTI_MIS_PLUGIN_DIR . 'offline.html';
            if (file_exists($offline_file)) {
                readfile($offline_file);
            } else {
                $this->output_default_offline_page();
            }
            exit;
        }
    }
    
    /**
     * Output service worker JavaScript
     */
    private function output_service_worker() {
        $portal_url = $this->get_portal_url();
        $css_url = MTTI_MIS_PLUGIN_URL . 'assets/css/learner-portal.css';
        $js_url = MTTI_MIS_PLUGIN_URL . 'assets/js/learner-portal.js';
        $logo_url = MTTI_MIS_PLUGIN_URL . 'assets/images/logo.jpeg';
        $offline_url = home_url('/mtti-offline.html');
        
        echo <<<JS
/**
 * MTTI Student Portal - Service Worker
 * Provides offline caching and fast loading
 */

const CACHE_NAME = 'mtti-portal-v1';
const OFFLINE_URL = '{$offline_url}';

// Files to cache immediately on install
const PRECACHE_URLS = [
    '/',
    '{$portal_url}',
    '/wp-login.php',
    '{$offline_url}',
    '{$css_url}',
    '{$js_url}',
    '{$logo_url}'
];

// Install event - cache essential files
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                console.log('MTTI PWA: Caching app shell');
                return cache.addAll(PRECACHE_URLS);
            })
            .then(() => self.skipWaiting())
    );
});

// Activate event - clean up old caches
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames
                    .filter(cacheName => cacheName !== CACHE_NAME)
                    .map(cacheName => caches.delete(cacheName))
            );
        }).then(() => self.clients.claim())
    );
});

// Fetch event - serve from cache, fall back to network
self.addEventListener('fetch', event => {
    // Skip non-GET requests
    if (event.request.method !== 'GET') return;
    
    // Skip admin and API requests
    const url = new URL(event.request.url);
    if (url.pathname.startsWith('/wp-admin') || 
        url.pathname.startsWith('/wp-json') ||
        url.pathname.includes('admin-ajax.php')) {
        return;
    }

    event.respondWith(
        caches.match(event.request)
            .then(cachedResponse => {
                if (cachedResponse) {
                    // Return cached version and update cache in background
                    event.waitUntil(updateCache(event.request));
                    return cachedResponse;
                }
                
                // Not in cache - fetch from network
                return fetch(event.request)
                    .then(response => {
                        // Cache successful responses
                        if (response.status === 200) {
                            const responseClone = response.clone();
                            caches.open(CACHE_NAME)
                                .then(cache => cache.put(event.request, responseClone));
                        }
                        return response;
                    })
                    .catch(() => {
                        // Network failed - show offline page for navigation requests
                        if (event.request.mode === 'navigate') {
                            return caches.match(OFFLINE_URL);
                        }
                    });
            })
    );
});

// Update cache in background
async function updateCache(request) {
    try {
        const response = await fetch(request);
        if (response.status === 200) {
            const cache = await caches.open(CACHE_NAME);
            await cache.put(request, response);
        }
    } catch (error) {
        // Network unavailable, keep using cached version
    }
}

// Handle push notifications (for future use)
self.addEventListener('push', event => {
    if (event.data) {
        const data = event.data.json();
        const options = {
            body: data.body || 'New notification from MTTI',
            icon: '{$logo_url}',
            badge: '{$logo_url}',
            vibrate: [100, 50, 100],
            data: {
                url: data.url || '{$portal_url}'
            }
        };
        event.waitUntil(
            self.registration.showNotification(data.title || 'MTTI Portal', options)
        );
    }
});

// Handle notification clicks
self.addEventListener('notificationclick', event => {
    event.notification.close();
    event.waitUntil(
        clients.openWindow(event.notification.data.url)
    );
});
JS;
    }
    
    /**
     * Output default offline page
     */
    private function output_default_offline_page() {
        $logo_url = MTTI_MIS_PLUGIN_URL . 'assets/images/logo.jpeg';
        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offline - MTTI Student Portal</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #2E7D32, #1B5E20);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .offline-card {
            background: white;
            border-radius: 16px;
            padding: 50px 40px;
            text-align: center;
            max-width: 400px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .logo { width: 80px; margin-bottom: 20px; border-radius: 50%; }
        h1 { color: #2E7D32; margin-bottom: 10px; font-size: 24px; }
        .motto { color: #FF9800; font-style: italic; margin-bottom: 20px; }
        p { color: #666; margin-bottom: 25px; line-height: 1.6; }
        .retry-btn {
            display: inline-block;
            background: #2E7D32;
            color: white;
            padding: 14px 32px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.3s;
        }
        .retry-btn:hover { background: #1B5E20; }
        .tips { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; text-align: left; }
        .tips h3 { color: #333; font-size: 14px; margin-bottom: 10px; }
        .tips ul { color: #666; font-size: 13px; padding-left: 20px; }
        .tips li { margin-bottom: 5px; }
    </style>
</head>
<body>
    <div class="offline-card">
        <img src="{$logo_url}" alt="MTTI Logo" class="logo">
        <h1>You're Offline</h1>
        <p class="motto">Start Learning, Start Earning</p>
        <p>It looks like you've lost your internet connection. Once you're back online, you can continue accessing your courses and results.</p>
        <a href="javascript:location.reload()" class="retry-btn">Try Again</a>
        
        <div class="tips">
            <h3>💡 While you wait:</h3>
            <ul>
                <li>Check your WiFi or mobile data</li>
                <li>Move to an area with better signal</li>
                <li>Try switching between WiFi and mobile data</li>
            </ul>
        </div>
    </div>
</body>
</html>
HTML;
    }
    
    /**
     * Get icon array for manifest
     */
    private function get_icons_array() {
        $sizes = array(72, 96, 128, 144, 152, 192, 384, 512);
        $icons = array();
        
        foreach ($sizes as $size) {
            $icon = array(
                'src' => $this->get_icon_url($size),
                'sizes' => "{$size}x{$size}",
                'type' => 'image/png'
            );
            
            // Add maskable purpose for larger icons
            if ($size >= 192) {
                $icon['purpose'] = 'any maskable';
            } else {
                $icon['purpose'] = 'any';
            }
            
            $icons[] = $icon;
        }
        
        return $icons;
    }
    
    /**
     * Get student portal URL
     */
    private function get_portal_url() {
        // Try to find the student portal page
        $portal_page = get_page_by_path('student-portal');
        if ($portal_page) {
            return get_permalink($portal_page->ID);
        }
        
        // Try learner-portal
        $portal_page = get_page_by_path('learner-portal');
        if ($portal_page) {
            return get_permalink($portal_page->ID);
        }
        
        // Default to /student-portal/
        return home_url('/student-portal/');
    }
    
    /**
     * Create student portal page if it doesn't exist
     */
    public function create_student_portal_page() {
        // Only run once
        if (get_option('mtti_portal_page_created')) {
            return;
        }
        
        // Check if page exists
        $portal_page = get_page_by_path('student-portal');
        if (!$portal_page) {
            // Create the page
            $page_id = wp_insert_post(array(
                'post_title'    => 'Student Portal',
                'post_name'     => 'student-portal',
                'post_content'  => '[mtti_learner_portal]',
                'post_status'   => 'publish',
                'post_type'     => 'page',
                'post_author'   => 1,
            ));
            
            if ($page_id && !is_wp_error($page_id)) {
                update_option('mtti_portal_page_created', true);
            }
        } else {
            update_option('mtti_portal_page_created', true);
        }
    }
}

// Initialize PWA support
add_action('plugins_loaded', function() {
    MTTI_MIS_PWA::get_instance();
}, 20);
