<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Free Drag Slider Captcha Demo</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="../frontend/css/slidercaptcha-improved.css">
    <link rel="stylesheet" href="../frontend/css/slidercaptcha-freedrag.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            background: linear-gradient(135deg, #ee7752, #e73c7e, #23a6d5, #23d5ab);
            background-size: 400% 400%;
            animation: gradient 15s ease infinite;
            min-height: 100vh;
            padding: 20px;
        }
        
        @keyframes gradient {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }
        
        .card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
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
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 10px;
        }
        
        .badge-new {
            background: linear-gradient(135deg, #f093fb, #f5576c);
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
        
        .mode-info {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
        }
        
        .mode-info h3 {
            font-size: 16px;
            margin-bottom: 10px;
        }
        
        .mode-info p {
            font-size: 13px;
            opacity: 0.95;
        }
        
        .features {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin: 20px 0;
        }
        
        .feature {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            border-left: 4px solid #667eea;
        }
        
        .feature h4 {
            font-size: 14px;
            color: #333;
            margin-bottom: 5px;
        }
        
        .feature p {
            font-size: 12px;
            color: #666;
        }
        
        #captcha {
            margin: 20px 0;
            padding: 20px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        /* Ensure canvas container has proper dimensions */
        #captcha .sc-captcha-box {
            width: 320px;
            height: 160px;
            margin: 0 auto;
            position: relative;
        }
        
        #captcha canvas {
            display: block;
        }
        
        .btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
            transform: none;
        }
        
        .message {
            margin-top: 20px;
            padding: 15px;
            border-radius: 8px;
            display: none;
            font-weight: 500;
            text-align: center;
            animation: slideIn 0.3s ease;
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
        
        .stats {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .stats h3 {
            font-size: 16px;
            color: #333;
            margin-bottom: 15px;
        }
        
        .stat-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
            font-size: 13px;
        }
        
        .stat-item:last-child {
            border-bottom: none;
        }
        
        .stat-label {
            color: #666;
        }
        
        .stat-value {
            color: #333;
            font-weight: 600;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Custom styles for free drag mode */
        .sc-info-container {
            background: linear-gradient(135deg, #667eea, #764ba2) !important;
            color: white !important;
        }
        
        .sc-block {
            transition: box-shadow 0.3s ease;
        }
        
        .sc-mode-toggle {
            background: rgba(0, 0, 0, 0.3);
            padding: 5px 8px;
            border-radius: 5px;
            transition: all 0.3s;
        }
        
        .sc-mode-toggle:hover {
            background: rgba(0, 0, 0, 0.5);
            transform: scale(1.1);
        }
        
        @media (max-width: 768px) {
            .container {
                grid-template-columns: 1fr;
            }
            
            .features {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Demo Card -->
        <div class="card">
            <h1>🎯 Free Drag Captcha <span class="badge badge-new">NEW</span></h1>
            <p class="subtitle">Drag the puzzle piece directly to its position</p>
            
            <div class="mode-info">
                <h3>🆕 Free Drag Mode</h3>
                <p>Click and drag the puzzle piece anywhere on the canvas to match its position. More intuitive and natural interaction!</p>
            </div>
            
            <div id="captcha"></div>
            
            <button class="btn btn-primary" id="submitBtn" disabled>
                Submit Form
            </button>
            
            <div id="message" class="message"></div>
            
            <div class="stats">
                <h3>📊 Current Stats</h3>
                <div class="stat-item">
                    <span class="stat-label">Mode:</span>
                    <span class="stat-value" id="currentMode">Free Drag</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Attempts:</span>
                    <span class="stat-value" id="attempts">0</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Success Rate:</span>
                    <span class="stat-value" id="successRate">0%</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Avg Time:</span>
                    <span class="stat-value" id="avgTime">0s</span>
                </div>
            </div>
        </div>
        
        <!-- Features Card -->
        <div class="card">
            <h1>✨ Dual Mode Features</h1>
            <p class="subtitle">Switch between slider and free drag modes</p>
            
            <div class="features">
                <div class="feature">
                    <h4>🎚️ Slider Mode</h4>
                    <p>Traditional horizontal slider control for precise movement</p>
                </div>
                <div class="feature">
                    <h4>🖱️ Free Drag Mode</h4>
                    <p>Direct manipulation - drag the piece anywhere on canvas</p>
                </div>
                <div class="feature">
                    <h4>🔄 Mode Toggle</h4>
                    <p>Click the icon in top-right to switch between modes</p>
                </div>
                <div class="feature">
                    <h4>📍 Visual Feedback</h4>
                    <p>Green glow when near the correct position</p>
                </div>
                <div class="feature">
                    <h4>🎯 2D Positioning</h4>
                    <p>Puzzle can move in both X and Y directions</p>
                </div>
                <div class="feature">
                    <h4>🔒 Secure Validation</h4>
                    <p>Server validates both drag distance and final position</p>
                </div>
            </div>
            
            <div class="mode-info" style="background: linear-gradient(135deg, #f093fb, #f5576c);">
                <h3>💡 How It Works</h3>
                <p><strong>Free Drag:</strong> Click and hold the puzzle piece, then drag it to match the outline in the background image.</p>
                <p style="margin-top: 10px;"><strong>Slider Mode:</strong> Use the slider at the bottom to move the piece horizontally.</p>
                <p style="margin-top: 10px;"><strong>Toggle:</strong> Click the icon (🖐️/⬌) in the top-right corner to switch modes.</p>
            </div>
            
            <div class="mode-info" style="background: linear-gradient(135deg, #13547a, #80d0c7);">
                <h3>🛡️ Security Features</h3>
                <p>• Movement trail analysis for bot detection</p>
                <p>• Minimum drag distance required</p>
                <p>• Time-based validation</p>
                <p>• Server-side position verification</p>
                <p>• One-time challenge tokens</p>
            </div>
        </div>
    </div>
    
    <script src="../frontend/js/slidercaptcha-freedrag-working.js"></script>
    <script>
        let captchaVerified = false;
        let attempts = 0;
        let successes = 0;
        let totalTime = 0;
        let startTime = 0;
        
        // Initialize captcha with free drag mode
        const captcha = new FreeDropSliderCaptcha('#captcha', {
            width: 320,
            height: 160,
            onSuccess: function() {
                captchaVerified = true;
                document.getElementById('submitBtn').disabled = false;
                showMessage('✅ Excellent! Captcha verified successfully!', 'success');
                
                // Update stats
                successes++;
                const endTime = Date.now();
                totalTime += (endTime - startTime) / 1000;
                updateStats();
            },
            onFail: function() {
                captchaVerified = false;
                document.getElementById('submitBtn').disabled = true;
                showMessage('❌ Not quite right. Try again!', 'error');
                
                // Update stats
                attempts++;
                updateStats();
            },
            onRefresh: function() {
                captchaVerified = false;
                document.getElementById('submitBtn').disabled = true;
                hideMessage();
                startTime = Date.now();
                attempts++;
                updateStats();
            }
        });
        
        // Start timer
        startTime = Date.now();
        
        // Mode is fixed to free drag in this demo
        
        function updateStats() {
            document.getElementById('attempts').textContent = attempts;
            
            const rate = attempts > 0 ? Math.round((successes / attempts) * 100) : 0;
            document.getElementById('successRate').textContent = rate + '%';
            
            const avgTime = successes > 0 ? (totalTime / successes).toFixed(1) : 0;
            document.getElementById('avgTime').textContent = avgTime + 's';
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
        
        // Handle form submission
        document.getElementById('submitBtn').addEventListener('click', function() {
            if (captchaVerified) {
                showMessage('🎉 Form submitted successfully! (Demo)', 'success');
                
                // Reset after 3 seconds
                setTimeout(() => {
                    captcha.reset();
                    captchaVerified = false;
                    document.getElementById('submitBtn').disabled = true;
                    hideMessage();
                    startTime = Date.now();
                }, 3000);
            }
        });
    </script>
</body>
</html>