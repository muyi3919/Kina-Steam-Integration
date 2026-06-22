<?php
/**
 * Plugin Name: Kina Steam Integration
 * Plugin URI:  https://kina.ink/2026/02/356/
 * Description: 绑定Steam账号，展示游戏库、在线状态和正在游玩的游戏
 * Version: 2.2
 * Author: Kina
 * Author URI:  https://kina.ink/
 * Text Domain: kina-steam
 */

if (!defined('ABSPATH')) exit;

define('KINA_STEAM_VERSION', '2.2');
define('KINA_STEAM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('KINA_STEAM_PLUGIN_URL', plugin_dir_url(__FILE__));

class KinaSteamIntegration {
    
    private $api_key_option = 'kina_steam_api_key';
    private $transient_prefix = 'kina_steam_';
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        
        // AJAX
        add_action('wp_ajax_kina_refresh_steam_data', array($this, 'ajax_refresh_steam_data'));
        add_action('wp_ajax_nopriv_kina_get_steam_status', array($this, 'ajax_get_steam_status'));
        
        // 短代码
        add_shortcode('kina_steam_profile', array($this, 'render_steam_profile'));
        add_shortcode('kina_steam_library', array($this, 'render_steam_library'));
        add_shortcode('kina_steam_status', array($this, 'render_steam_status'));
        
        // 定时任务
        add_action('kina_steam_cron', array($this, 'cron_refresh_data'));
        if (!wp_next_scheduled('kina_steam_cron')) {
            wp_schedule_event(time(), 'five_minutes', 'kina_steam_cron');
        }
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
    }
    
    public function add_cron_interval($schedules) {
        $schedules['five_minutes'] = array(
            'interval' => 300,
            'display' => '每5分钟'
        );
        return $schedules;
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Steam 集成',
            '🎮 Steam 集成',
            'manage_options',
            'kina-steam-integration',
            array($this, 'admin_page'),
            'dashicons-games',
            30
        );
    }
    
    public function register_settings() {
        register_setting('kina_steam_settings_group', $this->api_key_option);
        register_setting('kina_steam_settings_group', 'kina_steam_vanity_url');
        register_setting('kina_steam_settings_group', 'kina_steam_user_id');
    }
    
    private function get_steam_id() {
        $cached_id = get_option('kina_steam_user_id');
        if ($cached_id) return $cached_id;
        
        $vanity = get_option('kina_steam_vanity_url');
        if (!$vanity) return false;
        
        if (is_numeric($vanity) && strlen($vanity) == 17) {
            update_option('kina_steam_user_id', $vanity);
            return $vanity;
        }
        
        $api_key = get_option($this->api_key_option);
        if (!$api_key) return false;
        
        $url = "https://api.steampowered.com/ISteamUser/ResolveVanityURL/v1/?key={$api_key}&vanityurl={$vanity}";
        $response = wp_remote_get($url);
        
        if (is_wp_error($response)) return false;
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($data['response']['steamid'])) {
            update_option('kina_steam_user_id', $data['response']['steamid']);
            return $data['response']['steamid'];
        }
        
        return false;
    }
    
    private function get_player_summary($steam_id = null) {
        if (!$steam_id) $steam_id = $this->get_steam_id();
        if (!$steam_id) return false;
        
        $cache_key = $this->transient_prefix . 'player_' . $steam_id;
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;
        
        $api_key = get_option($this->api_key_option);
        $url = "https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/?key={$api_key}&steamids={$steam_id}";
        
        $response = wp_remote_get($url, array('timeout' => 10));
        if (is_wp_error($response)) return false;
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($data['response']['players'][0])) {
            $player = $data['response']['players'][0];
            set_transient($cache_key, $player, 300);
            return $player;
        }
        
        return false;
    }
    
    private function get_owned_games($steam_id = null) {
        if (!$steam_id) $steam_id = $this->get_steam_id();
        if (!$steam_id) return false;
        
        $cache_key = $this->transient_prefix . 'games_' . $steam_id;
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;
        
        $api_key = get_option($this->api_key_option);
        $url = "https://api.steampowered.com/IPlayerService/GetOwnedGames/v1/?key={$api_key}&steamid={$steam_id}&include_appinfo=1&include_played_free_games=1";
        
        $response = wp_remote_get($url, array('timeout' => 15));
        if (is_wp_error($response)) return false;
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($data['response']['games'])) {
            usort($data['response']['games'], function($a, $b) {
                $a_time = isset($a['playtime_forever']) ? $a['playtime_forever'] : 0;
                $b_time = isset($b['playtime_forever']) ? $b['playtime_forever'] : 0;
                return $b_time - $a_time;
            });
            set_transient($cache_key, $data['response']['games'], 3600);
            return $data['response']['games'];
        }
        
        return false;
    }
    
    private function get_recent_games($steam_id = null, $count = 5) {
        if (!$steam_id) $steam_id = $this->get_steam_id();
        if (!$steam_id) return false;
        
        $cache_key = $this->transient_prefix . 'recent_' . $steam_id;
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;
        
        $api_key = get_option($this->api_key_option);
        $url = "https://api.steampowered.com/IPlayerService/GetRecentlyPlayedGames/v1/?key={$api_key}&steamid={$steam_id}&count={$count}";
        
        $response = wp_remote_get($url, array('timeout' => 10));
        if (is_wp_error($response)) return false;
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($data['response']['games'])) {
            set_transient($cache_key, $data['response']['games'], 300);
            return $data['response']['games'];
        }
        
        return false;
    }
    
    private function get_status_config($state) {
        $configs = array(
            0 => array('text' => '离线', 'color' => '#848484', 'icon' => '⚫', 'class' => 'offline'),
            1 => array('text' => '在线', 'color' => '#57cbde', 'icon' => '🔵', 'class' => 'online'),
            2 => array('text' => '忙碌', 'color' => '#c02942', 'icon' => '🔴', 'class' => 'busy'),
            3 => array('text' => '离开', 'color' => '#f39c12', 'icon' => '🟡', 'class' => 'away'),
            4 => array('text' => '打盹', 'color' => '#8e44ad', 'icon' => '🟣', 'class' => 'snooze'),
            5 => array('text' => '想交易', 'color' => '#3498db', 'icon' => '💱', 'class' => 'trade'),
            6 => array('text' => '想玩游戏', 'color' => '#27ae60', 'icon' => '🎮', 'class' => 'play')
        );
        return $configs[$state] ?? $configs[0];
    }
    
    private function get_steam_level($steam_id = null) {
        if (!$steam_id) $steam_id = $this->get_steam_id();
        if (!$steam_id) return 0;
        
        $cache_key = $this->transient_prefix . 'level_' . $steam_id;
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;
        
        $api_key = get_option($this->api_key_option);
        $url = "https://api.steampowered.com/IPlayerService/GetSteamLevel/v1/?key={$api_key}&steamid={$steam_id}";
        
        $response = wp_remote_get($url);
        if (is_wp_error($response)) return 0;
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $level = isset($data['response']['player_level']) ? intval($data['response']['player_level']) : 0;
        
        set_transient($cache_key, $level, 3600);
        return $level;
    }
    
    public function render_steam_profile($atts) {
        $player = $this->get_player_summary();
        if (!$player) return '<div class="kina-steam-error">无法加载 Steam 数据</div>';
        
        $recent_games = $this->get_recent_games();
        $level = $this->get_steam_level();
        $status = $this->get_status_config($player['personastate']);
        
        $is_playing = isset($player['gameextrainfo']) && !empty($player['gameextrainfo']);
        $game_name = $is_playing ? $player['gameextrainfo'] : '';
        $game_id = $is_playing && isset($player['gameid']) ? $player['gameid'] : '';
        $avatar_frame = isset($player['avatarframe']) ? $player['avatarframe'] : '';
        
        ob_start();
        ?>
        <div class="kina-steam-profile" data-steam-id="<?php echo esc_attr($player['steamid']); ?>">
            <div class="kina-profile-bg" style="<?php echo isset($player['profilebackground']) ? 'background-image: url(' . esc_url($player['profilebackground']) . ')' : ''; ?>">
                <div class="kina-profile-bg-overlay"></div>
            </div>
            
            <div class="kina-profile-content">
                <div class="kina-profile-avatar-section">
                    <div class="kina-avatar-container">
                        <?php if ($avatar_frame): ?>
                        <div class="kina-avatar-frame">
                            <img src="<?php echo esc_url($avatar_frame); ?>" alt="">
                        </div>
                        <?php endif; ?>
                        <div class="kina-avatar-main">
                            <img src="<?php echo esc_url($player['avatarfull']); ?>" alt="<?php echo esc_attr($player['personaname']); ?>">
                            <div class="kina-status-glow <?php echo $is_playing ? 'playing' : $status['class']; ?>"></div>
                        </div>
                        <div class="kina-level-badge">
                            <span class="kina-level-num"><?php echo $level; ?></span>
                            <span class="kina-level-text">Lv</span>
                        </div>
                    </div>
                </div>
                
                <div class="kina-profile-info-section">
                    <h2 class="kina-profile-name"><?php echo esc_html($player['personaname']); ?></h2>
                    
                    <div class="kina-profile-status">
                        <?php if ($is_playing): ?>
                        <div class="kina-status-playing">
                            <span class="kina-pulse-dot"></span>
                            <span class="kina-status-label">游戏中</span>
                            <span class="kina-status-divider">-</span>
                            <a href="https://store.steampowered.com/app/<?php echo esc_attr($game_id); ?>" target="_blank" class="kina-game-name">
                                <?php echo esc_html($game_name); ?>
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="kina-status-normal <?php echo $status['class']; ?>">
                            <span class="kina-status-icon"><?php echo $status['icon']; ?></span>
                            <span class="kina-status-text"><?php echo $status['text']; ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="kina-profile-meta">
                        <?php if (isset($player['loccountrycode'])): ?>
                        <span class="kina-meta-item">📍 <?php echo esc_html($player['loccountrycode']); ?></span>
                        <?php endif; ?>
                        <span class="kina-meta-item">🎮 Steam ID: <?php echo esc_html($player['steamid']); ?></span>
                    </div>
                </div>
            </div>
            
            <?php if ($recent_games): ?>
            <div class="kina-profile-recent">
                <h3><span class="kina-section-icon">🎯</span> 最近游玩</h3>
                <div class="kina-recent-list">
                    <?php foreach (array_slice($recent_games, 0, 4) as $game): 
                        $weeks_hours = isset($game['playtime_2weeks']) ? round($game['playtime_2weeks'] / 60, 1) : 0;
                    ?>
                    <a href="https://store.steampowered.com/app/<?php echo $game['appid']; ?>" target="_blank" class="kina-recent-game">
                        <div class="kina-recent-cover">
                            <img src="https://cdn.cloudflare.steamstatic.com/steam/apps/<?php echo $game['appid']; ?>/library_600x900.jpg" 
                                 alt="<?php echo esc_attr($game['name']); ?>"
                                 onerror="this.src='https://cdn.cloudflare.steamstatic.com/steam/apps/<?php echo $game['appid']; ?>/header.jpg'">
                            <div class="kina-recent-overlay">
                                <span class="kina-view-btn">查看</span>
                            </div>
                        </div>
                        <div class="kina-recent-info">
                            <div class="kina-recent-title"><?php echo esc_html($game['name']); ?></div>
                            <div class="kina-recent-time">
                                <?php echo $weeks_hours > 0 ? $weeks_hours . ' 小时 (两周)' : '最近启动'; ?>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="kina-profile-footer">
                <a href="<?php echo esc_url($player['profileurl']); ?>" target="_blank" class="kina-steam-btn">
                    <svg class="kina-steam-icon" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/>
                    </svg>
                    <span>访问 Steam 主页</span>
                </a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function render_steam_library($atts) {
        $atts = shortcode_atts(array(
            'show_playtime' => 'true',
            'show_recent' => 'true',
            'columns' => 'auto'
        ), $atts, 'kina_steam_library');
        
        $games = $this->get_owned_games();
        if (!$games) return '<div class="kina-steam-error">无法加载游戏库，请确保 Steam 资料为公开</div>';
        
        $show_playtime = $atts['show_playtime'] === 'true';
        $show_recent = $atts['show_recent'] === 'true';
        $columns = $atts['columns'];
        
        // 获取最近游戏ID
        $recent_games = $this->get_recent_games(null, 10);
        $recent_ids = array();
        if ($recent_games) {
            foreach ($recent_games as $rg) {
                $recent_ids[] = $rg['appid'];
            }
        }
        
        $total_games = count($games);
        $total_hours = 0;
        foreach ($games as $game) {
            $total_hours += isset($game['playtime_forever']) ? intval($game['playtime_forever']) : 0;
        }
        $total_hours = round($total_hours / 60);
        
        ob_start();
        ?>
        <div class="kina-steam-library-full" <?php echo $columns !== 'auto' ? 'style="--grid-columns: ' . intval($columns) . '"' : ''; ?>>
            <div class="kina-library-header-full">
                <div class="kina-library-brand">
                    <div class="kina-library-icon">🎮</div>
                    <div class="kina-library-titles">
                        <h2>游戏库</h2>
                        <span class="kina-library-subtitle">Game Library</span>
                    </div>
                </div>
                <div class="kina-library-stats">
                    <div class="kina-stat-item">
                        <span class="kina-stat-num"><?php echo $total_games; ?></span>
                        <span class="kina-stat-label">款游戏</span>
                    </div>
                    <div class="kina-stat-divider"></div>
                    <div class="kina-stat-item">
                        <span class="kina-stat-num"><?php echo $total_hours; ?></span>
                        <span class="kina-stat-label">总时长(小时)</span>
                    </div>
                </div>
            </div>
            
            <div class="kina-games-grid-full">
                <?php foreach ($games as $game): 
                    $is_recent = in_array($game['appid'], $recent_ids);
                    $playtime_hours = isset($game['playtime_forever']) ? round($game['playtime_forever'] / 60, 1) : 0;
                    $playtime_weeks = isset($game['playtime_2weeks']) ? round($game['playtime_2weeks'] / 60, 1) : 0;
                ?>
                <a href="https://store.steampowered.com/app/<?php echo $game['appid']; ?>" 
                   target="_blank" 
                   class="kina-game-card <?php echo $is_recent ? 'is-recent' : ''; ?>"
                   title="<?php echo esc_attr($game['name']); ?>">
                    
                    <div class="kina-game-poster">
                        <img src="https://cdn.cloudflare.steamstatic.com/steam/apps/<?php echo $game['appid']; ?>/library_600x900.jpg" 
                             alt="<?php echo esc_attr($game['name']); ?>"
                             loading="lazy"
                             onerror="this.src='https://cdn.cloudflare.steamstatic.com/steam/apps/<?php echo $game['appid']; ?>/header.jpg'">
                        
                        <div class="kina-poster-overlay">
                            <div class="kina-overlay-content">
                                <span class="kina-view-game">查看商店</span>
                                <?php if ($show_playtime && $playtime_hours > 0): ?>
                                <span class="kina-playtime-badge"><?php echo $playtime_hours; ?>h</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if ($is_recent && $show_recent): ?>
                        <div class="kina-recent-tag">最近</div>
                        <?php endif; ?>
                        
                        <?php if ($show_playtime && $playtime_hours > 10): ?>
                        <div class="kina-hours-corner"><?php echo round($playtime_hours); ?>h</div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="kina-game-details">
                        <h4 class="kina-game-title"><?php echo esc_html($game['name']); ?></h4>
                        <?php if ($show_playtime): ?>
                        <div class="kina-game-meta">
                            <?php if ($playtime_weeks > 0): ?>
                            <span class="kina-meta-recent">📅 <?php echo $playtime_weeks; ?>h (两周)</span>
                            <?php else: ?>
                            <span class="kina-meta-total">⏱️ <?php echo $playtime_hours > 0 ? $playtime_hours . 'h' : '未游玩'; ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function render_steam_status($atts) {
        $player = $this->get_player_summary();
        if (!$player) return '';
        
        $status = $this->get_status_config($player['personastate']);
        $is_playing = isset($player['gameextrainfo']) && !empty($player['gameextrainfo']);
        $game_name = $is_playing ? $player['gameextrainfo'] : '';
        
        ob_start();
        ?>
        <div class="kina-steam-mini-status" data-steam-id="<?php echo esc_attr($player['steamid']); ?>">
            <div class="kina-mini-avatar-wrap">
                <img src="<?php echo esc_url($player['avatarmedium']); ?>" class="kina-mini-avatar" alt="">
                <div class="kina-mini-glow <?php echo $is_playing ? 'playing' : $status['class']; ?>"></div>
            </div>
            <div class="kina-mini-content">
                <div class="kina-mini-name"><?php echo esc_html($player['personaname']); ?></div>
                <div class="kina-mini-state">
                    <?php if ($is_playing): ?>
                    <div class="kina-mini-playing">
                        <span>游戏中</span>
                        <span class="kina-mini-divider">-</span>
                        <span class="kina-mini-game" title="<?php echo esc_attr($game_name); ?>"><?php echo esc_html($game_name); ?></span>
                    </div>
                    <?php else: ?>
                    <span class="kina-mini-text <?php echo $status['class']; ?>">
                        <span class="kina-dot" style="background: <?php echo $status['color']; ?>"></span>
                        <?php echo $status['text']; ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function ajax_get_steam_status() {
        $steam_id = $this->get_steam_id();
        if (!$steam_id) wp_send_json_error('未配置');
        
        $player = $this->get_player_summary($steam_id);
        if (!$player) wp_send_json_error('获取失败');
        
        $status = $this->get_status_config($player['personastate']);
        
        wp_send_json_success(array(
            'state' => $player['personastate'],
            'state_text' => $status['text'],
            'game' => isset($player['gameextrainfo']) ? $player['gameextrainfo'] : null,
            'game_id' => isset($player['gameid']) ? $player['gameid'] : null,
            'name' => $player['personaname'],
            'avatar' => $player['avatarmedium'],
            'is_playing' => isset($player['gameextrainfo']) && !empty($player['gameextrainfo'])
        ));
    }
    
    public function admin_page() {
        $api_key = get_option($this->api_key_option, '');
        $vanity = get_option('kina_steam_vanity_url', '');
        $steam_id = get_option('kina_steam_user_id', '');
        ?>
        <div class="wrap kina-steam-admin-v2">
            <div class="kina-admin-header">
                <h1>🎮 Kina Steam 集成</h1>
                <p>在网站展示你的 Steam 游戏库和在线状态</p>
            </div>
            
            <div class="kina-admin-section kina-guide-section">
                <h2>📖 快速使用指南</h2>
                <div class="kina-shortcode-guide">
                    <div class="kina-sc-card">
                        <div class="kina-sc-header">
                            <code>[kina_steam_profile]</code>
                            <span class="kina-sc-badge">推荐</span>
                        </div>
                        <div class="kina-sc-body">
                            <p><strong>完整个人资料卡片</strong></p>
                            <ul>
                                <li>✨ 显示 Steam 头像框</li>
                                <li>🎮 游戏中 - 游戏名（绿色脉冲）</li>
                                <li>🎯 最近游玩的4款游戏</li>
                                <li>📊 Steam 等级显示</li>
                            </ul>
                            <div class="kina-sc-preview">适合放在"关于我"页面</div>
                        </div>
                    </div>
                    
                    <div class="kina-sc-card">
                        <div class="kina-sc-header">
                            <code>[kina_steam_library]</code>
                            <span class="kina-sc-badge">全游戏</span>
                        </div>
                        <div class="kina-sc-body">
                            <p><strong>完整游戏库（全部游戏）</strong></p>
                            <ul>
                                <li>🖼️ 竖屏游戏封面（600x900）</li>
                                <li>📚 显示所有游戏，不分页</li>
                                <li>⏱️ 游戏时长统计</li>
                                <li>🏷️ "最近"标签标记</li>
                            </ul>
                            <div class="kina-sc-tip">参数：show_playtime="false" 隐藏时长</div>
                        </div>
                    </div>
                    
                    <div class="kina-sc-card">
                        <div class="kina-sc-header">
                            <code>[kina_steam_status]</code>
                            <span class="kina-sc-badge">紧凑</span>
                        </div>
                        <div class="kina-sc-body">
                            <p><strong>迷你状态栏</strong></p>
                            <ul>
                                <li>👤 小头像 + 状态光环</li>
                                <li>💬 游戏中 - 游戏名</li>
                                <li>⚡ 实时更新（30秒）</li>
                                <li>📱 适合侧边栏/导航</li>
                            </ul>
                            <div class="kina-sc-preview">适合放在网站顶部或侧边</div>
                        </div>
                    </div>
                </div>
                
                <div class="kina-usage-tips">
                    <h3>💡 使用技巧</h3>
                    <div class="kina-tips-grid">
                        <div class="kina-tip-item">
                            <span class="kina-tip-icon">🎨</span>
                            <div>
                                <strong>状态显示样式</strong>
                                <p>游戏中会显示为：<span class="kina-highlight">游戏中 - 艾尔登法环</span>（绿色脉冲动画）</p>
                            </div>
                        </div>
                        <div class="kina-tip-item">
                            <span class="kina-tip-icon">🖼️</span>
                            <div>
                                <strong>竖屏封面优先</strong>
                                <p>自动使用 Steam 竖屏封面（library_600x900.jpg），失败时回退到横版</p>
                            </div>
                        </div>
                        <div class="kina-tip-item">
                            <span class="kina-tip-icon">⚡</span>
                            <div>
                                <strong>实时更新</strong>
                                <p>在线状态和正在玩的游戏每30秒自动刷新，无需刷新页面</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="kina-admin-section">
                <h2>⚙️ 配置设置</h2>
                <form method="post" action="options.php">
                    <?php settings_fields('kina_steam_settings_group'); ?>
                    <table class="form-table kina-form-table">
                        <tr>
                            <th scope="row">
                                <label>Steam API Key</label>
                                <span class="kina-field-desc">用于获取 Steam 数据</span>
                            </th>
                            <td>
                                <input type="password" name="<?php echo $this->api_key_option; ?>" 
                                       value="<?php echo esc_attr($api_key); ?>" 
                                       class="kina-input"
                                       placeholder="XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX">
                                <p class="description">
                                    <a href="https://steamcommunity.com/dev/apikey" target="_blank" class="kina-link">点击获取 API Key</a>，域名填写：<code><?php echo esc_url(home_url()); ?></code>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label>Steam 自定义 URL</label>
                                <span class="kina-field-desc">你的 Steam 个人资料链接后缀</span>
                            </th>
                            <td>
                                <div class="kina-input-group">
                                    <span class="kina-input-prefix">steamcommunity.com/id/</span>
                                    <input type="text" name="kina_steam_vanity_url" 
                                           value="<?php echo esc_attr($vanity); ?>" 
                                           class="kina-input"
                                           placeholder="kina">
                                </div>
                                <?php if ($steam_id): ?>
                                <div class="kina-connection-status success">
                                    <span class="kina-status-icon">✅</span>
                                    <div>
                                        <strong>连接成功</strong>
                                        <span>Steam ID: <?php echo $steam_id; ?></span>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="kina-connection-status pending">
                                    <span class="kina-status-icon">⏳</span>
                                    <span>等待配置...</span>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('保存设置', 'primary', 'submit', true, array('class' => 'kina-save-btn')); ?>
                </form>
            </div>
            
            <?php if ($api_key && $steam_id): ?>
            <div class="kina-admin-section">
                <h2>🧪 连接测试</h2>
                <div class="kina-test-area">
                    <button type="button" class="button kina-test-btn" id="kina-test-connection">
                        <span class="kina-btn-icon">🔄</span>
                        测试 API 连接
                    </button>
                    <span class="spinner" style="float: none; margin-top: 0;"></span>
                </div>
                <div id="kina-test-result"></div>
            </div>
            
            <div class="kina-admin-notice">
                <span class="kina-notice-icon">🔒</span>
                <div>
                    <strong>隐私设置提醒</strong>
                    <p>如果游戏库显示为空，请确保 Steam 隐私设置为<strong>公开</strong>：编辑个人资料 → 隐私设置 → 游戏详情 → 公开</p>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    public function enqueue_scripts() {
        wp_enqueue_style('kina-steam', KINA_STEAM_PLUGIN_URL . 'assets/css/steam-style.css', array(), KINA_STEAM_VERSION);
        wp_enqueue_script('kina-steam', KINA_STEAM_PLUGIN_URL . 'assets/js/steam.js', array('jquery'), KINA_STEAM_VERSION, true);
        wp_localize_script('kina-steam', 'kinaSteam', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('kina_steam_nonce')
        ));
    }
    
    public function admin_enqueue_scripts($hook) {
        if ($hook !== 'toplevel_page_kina-steam-integration') return;
        wp_enqueue_style('kina-steam-admin', KINA_STEAM_PLUGIN_URL . 'assets/css/admin.css', array(), KINA_STEAM_VERSION);
        wp_enqueue_script('kina-steam-admin', KINA_STEAM_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), KINA_STEAM_VERSION, true);
        wp_localize_script('kina-steam-admin', 'kinaSteamAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('kina_steam_nonce')
        ));
    }
    
    public function ajax_refresh_steam_data() {
        check_ajax_referer('kina_steam_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('权限不足');
        
        $steam_id = $this->get_steam_id();
        delete_option('kina_steam_user_id');
        $new_id = $this->get_steam_id();
        
        if (!$new_id) wp_send_json_error('无法解析 Steam ID');
        
        delete_transient($this->transient_prefix . 'player_' . $new_id);
        delete_transient($this->transient_prefix . 'games_' . $new_id);
        delete_transient($this->transient_prefix . 'recent_' . $new_id);
        
        $player = $this->get_player_summary($new_id);
        if (!$player) wp_send_json_error('API 调用失败');
        
        wp_send_json_success(array(
            'steam_id' => $new_id,
            'name' => $player['personaname'],
            'state' => $this->get_status_config($player['personastate'])['text'],
            'game' => isset($player['gameextrainfo']) ? $player['gameextrainfo'] : '无',
            'level' => $this->get_steam_level($new_id)
        ));
    }
    
    public function cron_refresh_data() {
        $steam_id = $this->get_steam_id();
        if (!$steam_id) return;
        delete_transient($this->transient_prefix . 'player_' . $steam_id);
        delete_transient($this->transient_prefix . 'recent_' . $steam_id);
    }
}

register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('kina_steam_cron')) {
        wp_schedule_event(time(), 'five_minutes', 'kina_steam_cron');
    }
});

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('kina_steam_cron');
});


new KinaSteamIntegration();
