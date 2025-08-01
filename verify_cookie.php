<?php
/**
 * Giffgaff Cookie验证服务
 * 将Cookie转换为Access Token
 * 
 * 注意：此文件仅在支持PHP的服务器上可用
 * Netlify等静态托管服务不支持PHP
 */

// 设置CORS头
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// 处理预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 只允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method Not Allowed',
        'message' => '只允许POST请求'
    ]);
    exit();
}

// 获取POST数据
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// 检查是否是FormData格式
if (!$data) {
    $cookie = $_POST['cookie'] ?? '';
} else {
    $cookie = $data['cookie'] ?? '';
}

// 验证输入
if (empty($cookie)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Bad Request',
        'message' => 'Cookie参数不能为空'
    ]);
    exit();
}

// 记录请求日志
error_log("Cookie验证请求: " . date('Y-m-d H:i:s') . " - Cookie长度: " . strlen($cookie));

try {
    // 验证Cookie并获取Access Token
    $result = validateCookieAndGetToken($cookie);
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'accessToken' => $result['accessToken'],
            'message' => 'Cookie验证成功'
        ]);
    } else {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized',
            'message' => $result['message'] ?? 'Cookie验证失败'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Cookie验证错误: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal Server Error',
        'message' => '服务器内部错误'
    ]);
}

/**
 * 验证Cookie并获取Access Token
 * 
 * @param string $cookie Cookie字符串
 * @return array 验证结果
 */
function validateCookieAndGetToken($cookie) {
    // 解析Cookie
    $cookies = parseCookie($cookie);
    
    if (empty($cookies)) {
        return [
            'success' => false,
            'message' => 'Cookie格式无效'
        ];
    }
    
    // 检查必要的Cookie字段
    $requiredCookies = ['session_token', 'user_id', 'auth_token'];
    $foundCookies = [];
    
    foreach ($requiredCookies as $required) {
        if (isset($cookies[$required])) {
            $foundCookies[$required] = $cookies[$required];
        }
    }
    
    // 如果找不到关键Cookie，尝试其他可能的认证Cookie
    if (empty($foundCookies)) {
        // 查找任何包含token或session的Cookie
        foreach ($cookies as $name => $value) {
            if (strpos(strtolower($name), 'token') !== false || 
                strpos(strtolower($name), 'session') !== false ||
                strpos(strtolower($name), 'auth') !== false) {
                $foundCookies[$name] = $value;
            }
        }
    }
    
    if (empty($foundCookies)) {
        return [
            'success' => false,
            'message' => '未找到有效的认证Cookie'
        ];
    }
    
    // 尝试使用Cookie调用Giffgaff API验证
    $accessToken = callGiffgaffAPI($cookies);
    
    if ($accessToken) {
        return [
            'success' => true,
            'accessToken' => $accessToken
        ];
    } else {
        return [
            'success' => false,
            'message' => 'Cookie已过期或无效'
        ];
    }
}

/**
 * 解析Cookie字符串
 * 
 * @param string $cookieString Cookie字符串
 * @return array 解析后的Cookie数组
 */
function parseCookie($cookieString) {
    $cookies = [];
    $pairs = explode(';', $cookieString);
    
    foreach ($pairs as $pair) {
        $pair = trim($pair);
        if (empty($pair)) continue;
        
        $parts = explode('=', $pair, 2);
        if (count($parts) === 2) {
            $name = trim($parts[0]);
            $value = trim($parts[1]);
            $cookies[$name] = $value;
        }
    }
    
    return $cookies;
}

/**
 * 使用Cookie调用Giffgaff API获取Access Token
 * 
 * @param array $cookies Cookie数组
 * @return string|false Access Token或false
 */
function callGiffgaffAPI($cookies) {
    // 构建Cookie头
    $cookieHeader = '';
    foreach ($cookies as $name => $value) {
        $cookieHeader .= $name . '=' . $value . '; ';
    }
    $cookieHeader = rtrim($cookieHeader, '; ');
    
    // 尝试调用Giffgaff API验证Cookie
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://www.giffgaff.com/dashboard',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Cookie: ' . $cookieHeader,
            'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'Accept-Language: zh-CN,zh-HK;q=0.9,zh;q=0.8,en;q=0.7,en-GB;q=0.6,en-US;q=0.5',
            'Referer: https://www.giffgaff.com/auth/login/challenge',
            'Cache-Control: max-age=0',
            'DNT: 1',
            'Sec-Ch-Ua: "Not)A;Brand";v="8", "Chromium";v="138", "Microsoft Edge";v="138"',
            'Sec-Ch-Ua-Mobile: ?0',
            'Sec-Ch-Ua-Platform: "macOS"',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: same-origin',
            'Sec-Fetch-User: ?1',
            'Upgrade-Insecure-Requests: 1'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $htmlContent = $response;
        
        // 检查页面是否包含登录成功的标识
        $isLoggedIn = strpos($htmlContent, 'dashboard') !== false || 
                     strpos($htmlContent, 'account') !== false ||
                     strpos($htmlContent, 'profile') !== false ||
                     strpos($htmlContent, 'logout') !== false ||
                     strpos($htmlContent, 'login') === false;
        
        if ($isLoggedIn) {
            // Cookie有效，检查是否有关键的认证Cookie
            error_log('Cookie validation: Dashboard access successful');
            
            // 查找关键的认证Cookie
            $authCookies = ['GGUID', 'giffgaff', 'JSESSIONID', 'XSRF-TOKEN'];
            $foundAuthCookie = null;
            
            foreach ($authCookies as $cookieName) {
                if (isset($cookies[$cookieName])) {
                    $foundAuthCookie = $cookies[$cookieName];
                    error_log("Found auth cookie: $cookieName");
                    break;
                }
            }
            
            // 如果找到认证Cookie，使用它作为token
            if ($foundAuthCookie && strlen($foundAuthCookie) > 20) {
                return $foundAuthCookie;
            }
            
            // 使用任何包含token的Cookie
            foreach ($cookies as $name => $value) {
                if (strpos(strtolower($name), 'token') !== false && strlen($value) > 20) {
                    return $value;
                }
            }
            
            // 生成基于Cookie的临时token
            return base64_encode(json_encode([
                'cookies_hash' => md5(json_encode($cookies)),
                'timestamp' => time(),
                'source' => 'cookie_validation'
            ]));
        } else {
            error_log('Cookie validation: Dashboard access failed - redirected to login');
        }
    }
    
    return false;
}
?>