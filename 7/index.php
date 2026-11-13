<?php
/**
 * 私人留言板（PHP 单文件版）
 * 由 index.html + 留言板.css + 留言板.js 合并改造而来：
 *  - 留言存储从浏览器 localStorage 迁移到服务端（本文件同目录 messages.json）
 *  - 提交/删除均为 POST + PRG 重定向，防止刷新重复提交
 *  - 输出全部经过 htmlspecialchars 转义，防 XSS
 *  - 原粒子连线背景动画、原样式全部内联保留
 *  - 网址规范化：直接访问 /index.php 会 301 到目录地址，表单/跳转均不带 /index.php
 */

// ==================== 配置 ====================
// 数据文件用 .php 扩展名，文件头带 exit 守卫（PHP 开标签 + exit 语句）：
// 直接用 URL 访问它时只会被 PHP 解释成空输出退出，留言内容无法被下载
// 注意：注释里绝不能出现 PHP 结束标签字样，否则会提前终止 PHP 模式导致源码泄露
$MESSAGE_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'messages.dat.php';
$MESSAGE_GUARD = "<?php exit; ?>\n";
$MAX_MESSAGES = 5000;   // 最多保留条数（超出丢弃最旧）

// ==================== 会话无关的公共函数 ====================

/** 读取全部留言（时间升序） */
function loadMessages(string $file, string $guard): array
{
    if (!is_file($file)) {
        return [];
    }
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return [];
    }
    // 剥离 PHP 守卫行，取纯 JSON 部分
    if (strncmp($raw, $guard, strlen($guard)) === 0) {
        $raw = substr($raw, strlen($guard));
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return []; // 文件损坏时按空处理，不炸页面
    }
    return $data;
}

/** 保存全部留言（守卫头 + JSON） */
function saveMessages(string $file, string $guard, array $messages): bool
{
    $json = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }
    // 先写临时文件再原子重命名，避免并发写坏数据文件
    $tmp = $file . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $guard . $json, LOCK_EX) === false) {
        return false;
    }
    return @rename($tmp, $file);
}

/** 计算本应用所在的目录 URL（根部署返回 "/"，子目录返回 "/子目录/"） */
function baseDirUrl(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return ($dir === '' ? '' : $dir) . '/';
}

/** 跳回本页目录地址（PRG 模式，网址不出现 /index.php） */
function redirectSelf(): void
{
    header('Location: ' . baseDirUrl(), true, 303);
    exit;
}

/** 安全输出 */
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// ==================== 处理动作（仅接受 POST） ====================
$notice = ''; // 页面提示（错误时经 GET 参数带回）

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // CSRF 不引入（私人单页，无会话）；同源 POST 由浏览器默认保障

    if ($action === 'add') {
        $text = trim((string)($_POST['message'] ?? ''));
        if ($text !== '' && mb_strlen($text) <= 2000) {
            $messages = loadMessages($MESSAGE_FILE, $MESSAGE_GUARD);
            $messages[] = [
                'timestamp' => (int)round(microtime(true) * 1000),
                'text'      => $text,
            ];
            if (count($messages) > $MAX_MESSAGES) {
                $messages = array_slice($messages, -$MAX_MESSAGES);
            }
            if (!saveMessages($MESSAGE_FILE, $MESSAGE_GUARD, $messages)) {
                $notice = '保存失败：请检查本目录是否可写';
            }
        }
        redirectSelf();
    }

    if ($action === 'delete') {
        $ts = (int)($_POST['timestamp'] ?? 0);
        if ($ts > 0) {
            $messages = loadMessages($MESSAGE_FILE, $MESSAGE_GUARD);
            $messages = array_values(array_filter(
                $messages,
                fn($m) => (int)($m['timestamp'] ?? 0) !== $ts
            ));
            saveMessages($MESSAGE_FILE, $MESSAGE_GUARD, $messages);
        }
        redirectSelf();
    }
}

// 未知 action 一律回到 GET 渲染
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $notice = ($_GET['notice'] ?? '') === 'savefail' ? '保存失败：请检查本目录是否可写' : '';
    // 上面 redirectSelf 里带 notice 的分支实际未使用，保留简单兜底
}

// ==================== 网址规范化：/index.php → / ====================
// 用户直接输入或旧链接带着 /index.php 访问时，301 重定向到目录形式，
// 之后所有表单提交、PRG 跳转都指向目录地址，网址栏不再出现 /index.php
// 注意：REQUEST_URI 里的中文目录是百分号编码，SCRIPT_NAME 是解码后的，
// 必须先 rawurldecode 归一化再比较，否则子目录部署时判定失效
$uriPath    = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
if ($scriptName !== '' && $uriPath !== '' && rtrim($uriPath, '/') === rtrim($scriptName, '/')) {
    header('Location: ' . baseDirUrl(), true, 301);
    exit;
}

$messages = loadMessages($MESSAGE_FILE, $MESSAGE_GUARD);
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate'); // 不留缓存副本
header('X-Robots-Tag: noindex, noarchive, nosnippet');       // 不进搜索/存档缓存
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>私人留言板</title>
    <style>
body {
    font-family: Arial, sans-serif;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    margin: 0;
    padding-top: 50px;
}

.container {
    max-width: 600px;
    width: 100%;
    text-align: left;
    position: relative;
    z-index: 1;
}

textarea {
    width: 100%;
    height: 100px;
    margin-bottom: 10px;
    font-weight: bold;
}

#messages {
    margin-top: 60px;
}

#messageForm textarea {
    width: 100%;
    height: 100px;
    margin-bottom: 10px;
}

.message {
    background-color: #f4f4f4;
    padding: 10px;
    margin-bottom: 10px;
    border-radius: 5px;
}

.delete-button {
    float: right;
    cursor: pointer;
    color: red;
    background-color: #f0adad;
    border: none;
    padding: 5px 10px;
    border-radius: 5px;
    margin-left: 10px;
}

.delete-button:hover {
    background-color: #e09dad;
}

h1 {
    font-size: 48px;
    font-weight: 600;
    animation: rotate 0.3s ease infinite;
    width: fit-content;
    margin-bottom: 20px;
    position: relative;
    z-index: 1;
}

@keyframes rotate {
    0%   { transform: rotate(0); }
    20%  { transform: rotate(-2deg); }
    60%  { transform: rotate(0); }
    80%  { transform: rotate(2deg); }
    100% { transform: rotate(0); }
}

.notice {
    color: #c00;
    margin-bottom: 12px;
}
    </style>
</head>
<body>
<h1>专属记事薄</h1>
    <div class="container">
        <?php if ($notice !== ''): ?>
            <p class="notice"><?php echo h($notice); ?></p>
        <?php endif; ?>

        <form id="messageForm" method="post" action="<?php echo h(baseDirUrl()); ?>">
            <input type="hidden" name="action" value="add">
            <textarea id="messageInput" name="message" placeholder="请输入你的留言..." style="color: #dc6515"></textarea>
            <button type="submit">提交留言</button>
        </form>

        <form method="get" action="<?php echo h(baseDirUrl()); ?>" style="display:inline">
            <button type="submit" id="submitButton">显示留言</button>
        </form>

        <div id="messages">
<?php if ($messages): ?>
<?php   foreach (array_reverse($messages) as $m): // 最新的显示在最上面 ?>
            <div class="message">
                <span><?php echo h(date('Y-m-d H:i:s', intdiv((int)($m['timestamp'] ?? 0), 1000)) . ': ' . ($m['text'] ?? '')); ?></span>
                <form method="post" action="<?php echo h(baseDirUrl()); ?>" style="display:inline;float:right;margin:0">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="timestamp" value="<?php echo (int)($m['timestamp'] ?? 0); ?>">
                    <button type="submit" class="delete-button">删除</button>
                </form>
                <div style="clear:both"></div>
            </div>
<?php   endforeach; ?>
<?php else: ?>
            <p style="color:#999">还没有留言，写下第一条吧。</p>
<?php endif; ?>
        </div>
    </div>

    <script>
document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('messageForm');
    var messageInput = document.getElementById('messageInput');

    form.addEventListener('submit', function(e) {
        var text = messageInput.value.trim();
        if (text === '') {
            e.preventDefault();
            messageInput.focus();
            return;
        }
        if (text.length > 2000) {
            e.preventDefault();
            alert('留言太长了（最多 2000 字）');
        }
    });
});

/* 防查看：禁右键菜单 / 禁"查看源代码"类快捷键 / 禁选中文本 */
(function () {
    document.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    document.addEventListener('dragstart', function (e) { e.preventDefault(); });
    document.addEventListener('selectstart', function (e) {
        var t = e.target;
        if (t && (t.tagName === 'TEXTAREA' || (t.tagName === 'INPUT' && t.type !== 'hidden'))) return;
        e.preventDefault();
    });
    document.addEventListener('keydown', function (e) {
        var k = (e.key || '').toUpperCase();
        var blocked =
            e.key === 'F12' ||
            (e.ctrlKey && !e.shiftKey && (k === 'U' || k === 'S')) ||
            (e.ctrlKey && e.shiftKey && (k === 'I' || k === 'J' || k === 'C')) ||
            (e.metaKey && e.altKey && (k === 'I' || k === 'J' || k === 'C' || k === 'U'));
        if (blocked) { e.preventDefault(); e.returnValue = false; return false; }
    });
})();

!(function () {
    function n(n, e, t) {
        return n.getAttribute(e) || t;
    }
    function e(n) {
        return document.getElementsByTagName(n);
    }
    function t() {
        var t = e("script"),
            o = t.length,
            i = t[o - 1];
        return {
            l: o,
            z: n(i, "zIndex", -1),
            o: n(i, "opacity", 0.6),
            c: n(i, "color", "0,255,0"),
            n: n(i, "count", 400),
        };
    }

    function o() {
        (a = m.width =
            window.innerWidth ||
            document.documentElement.clientWidth ||
            document.body.clientWidth),
            (c = m.height =
            window.innerHeight ||
            document.documentElement.clientHeight ||
            document.body.clientHeight);
    }
    function i() {
        r.clearRect(0, 0, a, c);
        var n, e, t, o, m, l;
        s.forEach(function (particle, index) {
            for (
                particle.x += particle.xa,
                particle.y += particle.ya,
                particle.xa *= particle.x > a || particle.x < 0 ? -1 : 1,
                particle.ya *= particle.y > c || particle.y < 0 ? -1 : 1,
                r.fillStyle = particle.color,
                r.fillRect(particle.x - 0.5, particle.y - 0.5, 1, 1),
                e = index + 1;
                e < u.length;
                e++
            ) {
                (n = u[e]),
                null !== n.x &&
                null !== n.y &&
                ((o = particle.x - n.x),
                    (m = particle.y - n.y),
                    (l = o * o + m * m),
                    l < n.max &&
                    (n === y &&
                    l >= n.max / 2 &&
                    ((particle.x -= 0.03 * o), (particle.y -= 0.03 * m)),
                    (t = (n.max - l) / n.max),
                    r.beginPath(),
                    (r.lineWidth = t / 2),
                    r.strokeStyle = particle.color,
                    r.moveTo(particle.x, particle.y),
                    r.lineTo(n.x, n.y),
                    r.stroke()));
            }
        }),
            x(i);
    }
    var fixedColors = [
        "rgba(255, 0, 0, 1.0)",
        "rgba(0, 255, 0, 1.0)",
        "rgba(0, 0, 255, 1.0)",
        "rgba(255, 255, 0, 1.0)",
        "rgba(0, 255, 255, 0.8)",
        "rgba(255, 0, 255, 0.8)",
        "rgba(255, 165, 0, 0.8)",
        "rgba(127, 255, 212, 1.0)",
        "rgba(0, 255, 127, 1.0)"
    ];
    var a,
        c,
        u,
        m = document.createElement("canvas"),
        d = t(),
        l = "c_n" + d.l,
        r = m.getContext("2d"),
        x =
            window.requestAnimationFrame ||
            window.webkitRequestAnimationFrame ||
            window.mozRequestAnimationFrame ||
            window.oRequestAnimationFrame ||
            window.msRequestAnimationFrame ||
            function (n) {
                window.setTimeout(n, 1e3 / 45);
            },
        w = Math.random,
        y = { x: null, y: null, max: 2e4 };
    (m.id = l),
        (m.style.cssText =
            "position:fixed;top:0;left:0;z-index:" + d.z + ";opacity:" + d.o),
        e("body")[0].appendChild(m),
        o(),
        (window.onresize = o),
        (window.onmousemove = function (n) {
            (n = n || window.event), (y.x = n.clientX), (y.y = n.clientY);
        }),
        (window.onmouseout = function () {
            (y.x = null), (y.y = null);
        });

    for (var s = [], f = 0; d.n > f; f++) {
        var h = w() * a,
            g = w() * c,
            v = 2 * w() - 1,
            p = 2 * w() - 1,
            color = fixedColors[Math.floor(Math.random() * fixedColors.length)];
        s.push({ x: h, y: g, xa: v, ya: p, max: 6e3, color: color });
    }

    (u = s.concat([y])),
        setTimeout(function () {
            i();
        }, 100);
})();
    </script>
</body>
</html>
