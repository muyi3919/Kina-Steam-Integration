jQuery(document).ready(function($) {
    function updateSteamStatus() {
        if (document.hidden) return;
        
        $('.kina-steam-profile, .kina-steam-mini-status').each(function() {
            var $el = $(this);
            
            $.ajax({
                url: kinaSteam.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'kina_get_steam_status',
                    nonce: kinaSteam.nonce
                },
                success: function(response) {
                    if (response.success) {
                        updateUI($el, response.data);
                    }
                }
            });
        });
    }
    
    function updateUI($el, data) {
        // 更新迷你状态
        if ($el.hasClass('kina-steam-mini-status')) {
            var $state = $el.find('.kina-mini-state');
            var $glow = $el.find('.kina-mini-glow');
            
            if (data.is_playing) {
                $state.html('<span class="kina-mini-playing"><span class="kina-pulse"></span>游戏中 - ' + data.game + '</span>');
                $glow.attr('class', 'kina-mini-glow playing');
            } else {
                var statusClass = ['offline', 'online', 'busy', 'away', 'snooze', 'trade', 'play'][data.state] || 'offline';
                var statusColor = ['#848484', '#57cbde', '#c02942', '#f39c12', '#8e44ad', '#3498db', '#27ae60'][data.state] || '#848484';
                $state.html('<span class="kina-mini-text ' + statusClass + '"><span class="kina-dot" style="background:' + statusColor + '"></span>' + data.state_text + '</span>');
                $glow.attr('class', 'kina-mini-glow ' + statusClass);
            }
        }
        
        // 更新完整资料
        if ($el.hasClass('kina-steam-profile')) {
            var $statusSection = $el.find('.kina-profile-status');
            
            if (data.is_playing) {
                $statusSection.html(
                    '<div class="kina-status-playing">' +
                    '<span class="kina-pulse-dot"></span>' +
                    '<span class="kina-status-label">游戏中</span>' +
                    '<span class="kina-status-divider">-</span>' +
                    '<a href="https://store.steampowered.com/app/' + data.game_id + '" target="_blank" class="kina-game-name">' + data.game + '</a>' +
                    '</div>'
                );
            } else {
                var statusConfig = {
                    0: {text: '离线', icon: '⚫', class: 'offline'},
                    1: {text: '在线', icon: '🔵', class: 'online'},
                    2: {text: '忙碌', icon: '🔴', class: 'busy'},
                    3: {text: '离开', icon: '🟡', class: 'away'},
                    4: {text: '打盹', icon: '🟣', class: 'snooze'},
                    5: {text: '想交易', icon: '💱', class: 'trade'},
                    6: {text: '想玩游戏', icon: '🎮', class: 'play'}
                };
                var cfg = statusConfig[data.state] || statusConfig[0];
                $statusSection.html(
                    '<div class="kina-status-normal ' + cfg.class + '">' +
                    '<span class="kina-status-icon">' + cfg.icon + '</span>' +
                    '<span class="kina-status-text">' + cfg.text + '</span>' +
                    '</div>'
                );
            }
        }
    }
    
    setInterval(updateSteamStatus, 30000);
    
    $(document).on('visibilitychange', function() {
        if (!document.hidden) updateSteamStatus();
    });
});