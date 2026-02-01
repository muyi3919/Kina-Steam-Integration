jQuery(document).ready(function($) {
    $('#kina-test-connection').on('click', function() {
        var $btn = $(this);
        var $spinner = $btn.next('.spinner');
        var $result = $('#kina-test-result');
        
        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $result.html('');
        
        $.ajax({
            url: kinaSteamAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'kina_refresh_steam_data',
                nonce: kinaSteamAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    var html = '<div class="notice notice-success"><p>';
                    html += '<strong>✅ 连接成功！</strong><br><br>';
                    html += '👤 用户名: ' + response.data.name + '<br>';
                    html += '🆔 Steam ID: ' + response.data.steam_id + '<br>';
                    html += '📊 等级: Lv.' + response.data.level + '<br>';
                    html += '🌐 当前状态: ' + response.data.state + '<br>';
                    html += '🎮 正在游戏: ' + (response.data.game !== '无' ? response.data.game : '无');
                    html += '</p></div>';
                    $result.html(html);
                } else {
                    $result.html('<div class="notice notice-error"><p><strong>❌ 错误:</strong> ' + response.data + '</p></div>');
                }
            },
            error: function() {
                $result.html('<div class="notice notice-error"><p><strong>❌ 请求失败</strong>，请检查网络连接</p></div>');
            },
            complete: function() {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
            }
        });
    });
});