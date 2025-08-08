# 🔐 Secure Slider Captcha

A **secure, challenge-response based** slider captcha implementation with **anti-replay protection** and **server-side validation**.

## 🛡️ Security Features

- ✅ **One-time challenge tokens** - Each challenge can only be used once
- ✅ **Server-side puzzle position validation** - Position stored and validated on server
- ✅ **Anti-replay protection** - Prevents reuse of valid requests
- ✅ **Challenge expiration** - 5-minute timeout for challenges
- ✅ **Rate limiting** - Maximum 5 attempts per challenge
- ✅ **Bot detection** - Analyzes movement patterns and velocity
- ✅ **Session-based verification** - Secure token generation

## 📁 Project Structure

```
SliderCaptcha/
├── backend/
│   ├── php/
│   │   └── SliderCaptchaController-secure.php  # PHP implementation
│   ├── dotnet/
│   │   ├── SliderCaptchaController.NET45.cs    # .NET 4.5 implementation
│   │   ├── SliderCaptchaController.NET48.cs    # .NET 4.8 implementation
│   │   └── Web.config.example                  # IIS configuration
│   └── java/
│       ├── SliderCaptchaController.java        # Spring Boot implementation
│       ├── pom.xml                             # Maven dependencies
│       └── application.properties              # Spring configuration
├── frontend/
│   ├── js/
│   │   └── slidercaptcha-secure.js             # Secure JavaScript client
│   ├── css/
│   │   └── slidercaptcha-improved.css          # Styles
│   └── images/
│       └── Pic0.jpg - Pic4.jpg                 # Sample images
├── demos/
│   └── index.php                               # Live demo
└── README.md
```

## 🚀 Quick Start

### Run the Demo

```bash
# Method 1: Using the provided script
./run-demo.sh

# Method 2: Direct PHP command
php -S localhost:8000

# Then open in browser:
# http://localhost:8000/demos/
```

### PHP Implementation

1. **Setup:**
```bash
# Copy files to your web server
cp backend/php/SliderCaptchaController-secure.php /var/www/your-app/
cp -r frontend/* /var/www/your-app/assets/
```

2. **Include in your HTML:**
```html
<link rel="stylesheet" href="assets/css/slidercaptcha-improved.css">
<script src="assets/js/slidercaptcha-secure.js"></script>
```

3. **Initialize:**
```javascript
const captcha = new SecureSliderCaptcha('#captcha', {
    challengeUrl: 'SliderCaptchaController-secure.php?action=challenge',
    verifyUrl: 'SliderCaptchaController-secure.php?action=verify',
    onSuccess: function() {
        console.log('Verified!');
    }
});
```

### .NET Implementation

1. **For .NET 4.5:** Use `SliderCaptchaController.NET45.cs` as HttpHandler
2. **For .NET 4.8:** Use `SliderCaptchaController.NET48.cs` as Web API Controller

Configure in `Web.config`:
```xml
<system.web>
  <httpHandlers>
    <add verb="*" path="SliderCaptcha.ashx" 
         type="SliderCaptcha.SliderCaptchaHandler, YourAssembly" />
  </httpHandlers>
</system.web>
```

### Java Spring Boot Implementation

1. **Add to your Spring Boot project:**
```bash
cp backend/java/SliderCaptchaController.java src/main/java/com/example/
```

2. **Add dependencies to `pom.xml`**

3. **Configure in `application.properties`:**
```properties
captcha.storage.path=/tmp/captcha_sessions/
captcha.challenge.expiry.minutes=5
```

## 🔒 How It Works

1. **Client requests challenge** → Server generates unique `challengeId` with random puzzle position
2. **Server stores challenge** → Position and metadata saved server-side
3. **Client renders puzzle** → Using server-provided position
4. **User solves puzzle** → Client sends `challengeId` + movement trail
5. **Server validates** → Checks position, movement patterns, and marks challenge as used
6. **One-time use** → Challenge is deleted after successful verification

## 🧪 Testing Security

The demo includes attack simulation buttons:

- **Replay Attack Test** - Captures and replays a valid request
- **Fake Trail Test** - Sends fabricated movement data
- **Challenge Reuse Test** - Attempts to reuse a consumed challenge

All attacks should fail with appropriate error messages.

## 📊 API Endpoints

### Generate Challenge
```
GET /api/slidercaptcha/challenge

Response:
{
    "challengeId": "abc123...",
    "targetX": 150,
    "timestamp": 1234567890
}
```

### Verify Solution
```
POST /api/slidercaptcha/verify

Body:
{
    "challengeId": "abc123...",
    "trail": [
        {"x": 0, "y": 0, "t": 0},
        {"x": 10, "y": 1, "t": 100},
        ...
    ]
}

Response:
{
    "verified": true,
    "token": "success_token_xyz"
}
```

## 🔧 Configuration

### PHP
- Session storage path: `/tmp/captcha_sessions/`
- Challenge expiry: 5 minutes
- Max attempts: 5
- Position tolerance: 10px

### .NET
- Storage: In-memory with file fallback
- Session timeout: 20 minutes
- CORS: Configurable in Web.config

### Java
- Storage: ConcurrentHashMap with file persistence
- Scheduled cleanup: Every minute
- Optional Redis support

## 📝 Requirements

- **PHP**: 7.4+ with session support
- **.NET**: Framework 4.5+ or 4.8+
- **Java**: JDK 11+, Spring Boot 2.7+
- **Browser**: Modern browser with Canvas API support

## 🤝 Contributing

1. Fork the repository
2. Create your feature branch
3. Ensure all security measures are maintained
4. Submit a pull request

## 📄 License

MIT License - See LICENSE file for details

## ⚠️ Security Note

This implementation is designed to prevent:
- Replay attacks
- Automated bot submissions
- Challenge reuse
- Brute force attempts
- Session hijacking

Always use HTTPS in production and configure appropriate CORS policies.