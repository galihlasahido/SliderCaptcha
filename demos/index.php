<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SECURE Slider Captcha Demo</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="../frontend/css/slidercaptcha-improved.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }
        
        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 30px;
        }
        
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 24px;
        }
        
        .badge {
            display: inline-block;
            background: #dc3545;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 10px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .subtitle {
            color: #666;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
            font-size: 14px;
        }
        
        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        #captcha {
            margin: 20px 0;
        }
        
        .btn {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-transform: uppercase;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        
        .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }
        
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .message {
            margin-top: 20px;
            padding: 15px;
            border-radius: 8px;
            display: none;
            font-weight: 500;
        }
        
        .message.show {
            display: block;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .security-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .security-info h3 {
            font-size: 16px;
            color: #333;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .security-info ul {
            list-style: none;
            padding: 0;
        }
        
        .security-info li {
            padding: 8px 0;
            font-size: 13px;
            color: #555;
            display: flex;
            align-items: center;
        }
        
        .security-info li::before {
            content: '🔒';
            margin-right: 10px;
        }
        
        .attack-test {
            margin-top: 20px;
            padding: 20px;
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 10px;
        }
        
        .attack-test h3 {
            font-size: 16px;
            color: #856404;
            margin-bottom: 15px;
        }
        
        .attack-test button {
            margin: 5px 0;
            padding: 8px 15px;
            background: #ffc107;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.3s;
        }
        
        .attack-test button:hover {
            background: #e0a800;
        }
        
        .code-block {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 6px;
            margin-top: 10px;
            font-family: 'Courier New', monospace;
            font-size: 11px;
            overflow-x: auto;
            max-height: 200px;
            overflow-y: auto;
        }
        
        @media (max-width: 768px) {
            .container {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Form Card -->
        <div class="card">
            <h1>🔐 Secure Login <span class="badge">SECURE</span></h1>
            <p class="subtitle">Server-validated with anti-replay protection</p>
            
            <form id="loginForm" onsubmit="return handleSubmit(event)">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required>
                </div>
                
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required>
                </div>
                
                <div class="form-group">
                    <label>Security Verification</label>
                    <div id="captcha"></div>
                </div>
                
                <button type="submit" class="btn btn-primary" id="submitBtn" disabled>
                    Submit Form
                </button>
            </form>
            
            <div id="message" class="message"></div>
            
            <div class="security-info">
                <h3><i class="fa fa-shield-alt"></i> Security Features</h3>
                <ul>
                    <li>One-time challenge tokens</li>
                    <li>Server-side position validation</li>
                    <li>Anti-replay protection</li>
                    <li>Challenge expiration (5 minutes)</li>
                    <li>Max 5 attempts per challenge</li>
                    <li>Session-based verification</li>
                </ul>
            </div>
        </div>
        
        <!-- Testing Card -->
        <div class="card">
            <h1>🧪 Security Testing</h1>
            <p class="subtitle">Try these attack scenarios</p>
            
            <div class="attack-test">
                <h3>❌ Replay Attack Test</h3>
                <p style="font-size: 13px; margin-bottom: 10px;">
                    Capture a valid request and try to replay it
                </p>
                <button onclick="testReplay()">Capture & Replay Last Request</button>
                <div id="replayResult"></div>
            </div>
            
            <div class="attack-test">
                <h3>❌ Fake Trail Test</h3>
                <p style="font-size: 13px; margin-bottom: 10px;">
                    Send a fake trail without solving the puzzle
                </p>
                <button onclick="testFakeTrail()">Send Fake Trail</button>
                <div id="fakeResult"></div>
            </div>
            
            <div class="attack-test">
                <h3>❌ Challenge Reuse Test</h3>
                <p style="font-size: 13px; margin-bottom: 10px;">
                    Try to use the same challenge twice
                </p>
                <button onclick="testChallengeReuse()">Reuse Challenge</button>
                <div id="reuseResult"></div>
            </div>
            
            <div class="code-block" id="debugOutput">
                Debug output will appear here...
            </div>
        </div>
    </div>
    
    <script src="../frontend/js/slidercaptcha-secure.js"></script>
    <script>
        let captchaVerified = false;
        let lastRequest = null;
        let lastChallengeId = null;
        
        // Initialize secure captcha
        const captcha = new SecureSliderCaptcha('#captcha', {
            width: 320,
            height: 160,
            challengeUrl: '../backend/php/SliderCaptchaController-secure.php?action=challenge',
            verifyUrl: '../backend/php/SliderCaptchaController-secure.php?action=verify',
            onSuccess: function() {
                captchaVerified = true;
                document.getElementById('submitBtn').disabled = false;
                showMessage('✅ Securely verified! Challenge consumed.', 'success');
                addDebug('SUCCESS: Challenge verified and consumed on server');
            },
            onFail: function() {
                captchaVerified = false;
                document.getElementById('submitBtn').disabled = true;
                showMessage('❌ Verification failed.', 'error');
                addDebug('FAILED: Server rejected the solution');
            },
            onRefresh: function() {
                captchaVerified = false;
                document.getElementById('submitBtn').disabled = true;
                hideMessage();
                addDebug('REFRESH: Requesting new challenge from server');
            }
        });
        
        // Intercept fetch to capture requests
        const originalFetch = window.fetch;
        window.fetch = function(...args) {
            if (args[0].includes('verify')) {
                const [url, options] = args;
                if (options && options.body) {
                    lastRequest = {
                        url: url,
                        body: options.body
                    };
                    const data = JSON.parse(options.body);
                    lastChallengeId = data.challengeId;
                    addDebug(`CAPTURED: Verification request with challenge ${data.challengeId.substring(0, 8)}...`);
                }
            }
            return originalFetch.apply(this, args);
        };
        
        function handleSubmit(event) {
            event.preventDefault();
            
            if (!captchaVerified) {
                showMessage('⚠️ Please complete the captcha', 'error');
                return false;
            }
            
            showMessage('🎉 Form submitted successfully!', 'success');
            
            // Reset after submission
            setTimeout(() => {
                document.getElementById('loginForm').reset();
                captcha.reset();
                captchaVerified = false;
                document.getElementById('submitBtn').disabled = true;
                hideMessage();
            }, 3000);
            
            return false;
        }
        
        async function testReplay() {
            if (!lastRequest) {
                document.getElementById('replayResult').innerHTML = 
                    '<p style="color: red; font-size: 12px; margin-top: 10px;">No request captured yet. Solve the captcha first.</p>';
                return;
            }
            
            addDebug('REPLAY ATTACK: Attempting to replay last request...');
            
            try {
                const response = await fetch(lastRequest.url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: lastRequest.body
                });
                
                const result = await response.json();
                
                document.getElementById('replayResult').innerHTML = 
                    `<p style="color: ${result.verified ? 'green' : 'red'}; font-size: 12px; margin-top: 10px;">
                    ${result.verified ? '⚠️ VULNERABLE!' : '✅ PROTECTED!'} 
                    Server response: ${result.error || result.message}
                    </p>`;
                
                addDebug(`REPLAY RESULT: ${JSON.stringify(result)}`);
            } catch (error) {
                document.getElementById('replayResult').innerHTML = 
                    '<p style="color: red; font-size: 12px; margin-top: 10px;">Error: ' + error.message + '</p>';
            }
        }
        
        async function testFakeTrail() {
            // Generate fake trail data
            const fakeTrail = [];
            for (let i = 0; i < 50; i++) {
                fakeTrail.push({
                    x: i * 5,
                    y: Math.random() * 10 - 5,
                    t: i * 20
                });
            }
            
            const fakeData = {
                challengeId: 'fake-challenge-id-12345',
                trail: fakeTrail
            };
            
            addDebug('FAKE TRAIL ATTACK: Sending fabricated trail data...');
            
            try {
                const response = await fetch('../backend/php/SliderCaptchaController-secure.php?action=verify', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify(fakeData)
                });
                
                const result = await response.json();
                
                document.getElementById('fakeResult').innerHTML = 
                    `<p style="color: ${result.verified ? 'green' : 'red'}; font-size: 12px; margin-top: 10px;">
                    ${result.verified ? '⚠️ VULNERABLE!' : '✅ PROTECTED!'} 
                    Server response: ${result.error || result.message}
                    </p>`;
                
                addDebug(`FAKE TRAIL RESULT: ${JSON.stringify(result)}`);
            } catch (error) {
                document.getElementById('fakeResult').innerHTML = 
                    '<p style="color: red; font-size: 12px; margin-top: 10px;">Error: ' + error.message + '</p>';
            }
        }
        
        async function testChallengeReuse() {
            if (!lastChallengeId) {
                document.getElementById('reuseResult').innerHTML = 
                    '<p style="color: red; font-size: 12px; margin-top: 10px;">No challenge ID captured. Solve the captcha first.</p>';
                return;
            }
            
            // Try to reuse the last challenge ID with a new trail
            const fakeTrail = [];
            for (let i = 0; i < 30; i++) {
                fakeTrail.push({
                    x: i * 8,
                    y: Math.random() * 5,
                    t: i * 25
                });
            }
            
            const reuseData = {
                challengeId: lastChallengeId,
                trail: fakeTrail
            };
            
            addDebug(`CHALLENGE REUSE: Attempting to reuse challenge ${lastChallengeId.substring(0, 8)}...`);
            
            try {
                const response = await fetch('../backend/php/SliderCaptchaController-secure.php?action=verify', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify(reuseData)
                });
                
                const result = await response.json();
                
                document.getElementById('reuseResult').innerHTML = 
                    `<p style="color: ${result.verified ? 'green' : 'red'}; font-size: 12px; margin-top: 10px;">
                    ${result.verified ? '⚠️ VULNERABLE!' : '✅ PROTECTED!'} 
                    Server response: ${result.error || result.message}
                    </p>`;
                
                addDebug(`REUSE RESULT: ${JSON.stringify(result)}`);
            } catch (error) {
                document.getElementById('reuseResult').innerHTML = 
                    '<p style="color: red; font-size: 12px; margin-top: 10px;">Error: ' + error.message + '</p>';
            }
        }
        
        function showMessage(text, type) {
            const messageEl = document.getElementById('message');
            messageEl.textContent = text;
            messageEl.className = 'message show ' + type;
        }
        
        function hideMessage() {
            const messageEl = document.getElementById('message');
            messageEl.className = 'message';
        }
        
        function addDebug(text) {
            const debugEl = document.getElementById('debugOutput');
            const timestamp = new Date().toLocaleTimeString();
            debugEl.innerHTML = `[${timestamp}] ${text}<br>` + debugEl.innerHTML;
            
            // Keep only last 10 messages
            const lines = debugEl.innerHTML.split('<br>');
            if (lines.length > 10) {
                debugEl.innerHTML = lines.slice(0, 10).join('<br>');
            }
        }
        
        // Initial debug message
        addDebug('SECURE MODE: Waiting for user interaction...');
    </script>
</body>
</html>