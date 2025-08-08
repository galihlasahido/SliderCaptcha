# Secure Slider Captcha - Technical Documentation

## Overview

This is a **secure** slider captcha implementation that prevents automated attacks by never exposing the puzzle solution to the client. The system uses server-side image generation and session-based validation to ensure that bots cannot bypass the captcha.

## 🔒 Security Architecture

### Core Security Principle
**The target position is NEVER sent to the client.** All puzzle validation happens server-side using session-stored coordinates.

### Security Features
- **Server-Side Image Generation**: Puzzle images are generated dynamically on the server
- **Position Protection**: Target X,Y coordinates exist only in server session
- **Cryptographic IDs**: Challenge IDs use secure random generation
- **Rate Limiting**: 10 requests per minute per IP address
- **Session Management**: 5-minute challenge expiration
- **Attempt Limiting**: Maximum 5 attempts per challenge
- **Input Validation**: Strict validation of all inputs

## 📁 Project Structure

```
SliderCaptcha/
├── backend/
│   ├── php/
│   │   └── secure-captcha.php              # PHP implementation with GD
│   ├── dotnet/
│   │   └── SecureSliderCaptcha.cs          # .NET implementation
│   └── java/
│       └── SecureSliderCaptchaController.java  # Spring Boot implementation
├── frontend/
│   ├── js/
│   │   ├── secure-captcha.js               # Free drag interface
│   │   └── secure-slider-captcha.js        # Slider interface
│   ├── css/
│   │   └── slidercaptcha-improved.css      # Styles
│   └── images/
│       └── Pic*.jpg                        # Sample images
├── demos/
│   ├── working-demo.html                   # Basic demo
│   └── slider-demo.html                    # Slider vs free drag demo
└── TECHNICAL-README.md                     # This file
```

## 🚀 Implementation Details

### Backend Implementations

All backend implementations follow the same secure pattern:

#### PHP (secure-captcha.php)
- Uses PHP GD library for image generation
- Session-based challenge storage
- Supports both slider and free-drag modes

#### .NET (SecureSliderCaptcha.cs)
- Uses System.Drawing for image generation
- IHttpHandler implementation
- Compatible with .NET Framework 4.5+ and .NET Core

#### Java (SecureSliderCaptchaController.java)
- Spring Boot REST controller
- Java AWT for image generation
- Session-scoped challenge management

### API Endpoints

All implementations provide these endpoints:

| Endpoint | Method | Description | Response |
|----------|--------|-------------|----------|
| `/challenge` | GET | Generate new challenge | `{challengeId, imageWidth, imageHeight, pieceSize}` |
| `/background` | GET | Get background with hole | PNG image |
| `/piece` | GET | Get puzzle piece | PNG image |
| `/verify` | POST | Verify solution | `{verified, attempts_left}` |

### Frontend Implementations

#### secure-captcha.js (Free Drag)
- Allows dragging puzzle piece anywhere on canvas
- Full X,Y positioning freedom
- Touch and mouse support

#### secure-slider-captcha.js (Slider)
- Traditional slider interface
- Horizontal movement only
- Fixed Y position for easier solving

## 🔐 Security Comparison

### ❌ Traditional Vulnerable Approach
```json
// Client receives target position - EXPLOITABLE!
{
  "challengeId": "abc123",
  "targetX": 142,  // Attacker knows where to place piece
  "targetY": 75    // Can automate solution
}
```

### ✅ Our Secure Approach
```json
// Client receives only rendering information
{
  "challengeId": "xyz789",
  "imageWidth": 320,
  "imageHeight": 200,
  "pieceSize": 50
  // Target position stays on server!
}
```

## 🛠️ Installation & Usage

### PHP Setup
```bash
# Verify GD library
php -m | grep gd

# Start server
php -S localhost:8000

# Access demo
http://localhost:8000/demos/working-demo.html
```

### .NET Setup
```xml
<!-- Web.config -->
<system.web>
  <httpHandlers>
    <add verb="*" path="captcha.ashx" 
         type="SliderCaptcha.SecureSliderCaptchaHandler"/>
  </httpHandlers>
</system.web>
```

### Java Spring Boot Setup
```java
@SpringBootApplication
@ComponentScan(basePackages = {"com.slidercaptcha"})
public class Application {
    public static void main(String[] args) {
        SpringApplication.run(Application.class, args);
    }
}
```

## 🎯 Usage Example

```javascript
// 1. Initialize captcha
const captcha = new SecureCaptcha('#captchaContainer', {
    baseUrl: '/backend/php/secure-captcha.php',
    onSuccess: () => console.log('Solved!'),
    onFail: () => console.log('Try again')
});

// 2. Images load automatically
// Background: /backend/php/secure-captcha.php?action=background&id=xxx
// Piece: /backend/php/secure-captcha.php?action=piece&id=xxx

// 3. User drags piece to match hole

// 4. Verification happens automatically on drop
// POST /backend/php/secure-captcha.php?action=verify
// Body: { challengeId: "xxx", x: 150, y: 75 }
```

## 🔍 How It Works

### 1. Challenge Generation
```
Client                          Server
------                          ------
GET /challenge         →        Generate random position
                               Store in session: {x: 142, y: 75}
                       ←        Return: {challengeId: "abc", pieceSize: 50}
                               (No position data!)
```

### 2. Image Loading
```
Client                          Server
------                          ------
GET /background?id=abc →       Load position from session
                               Generate image with hole at (142, 75)
                       ←        Return: PNG image

GET /piece?id=abc     →        Generate matching puzzle piece
                       ←        Return: PNG image
```

### 3. Verification
```
Client                          Server
------                          ------
User drags to (140, 73)
POST /verify          →        Load expected position from session
{id: "abc",                    Compare: |140-142| < 5 && |73-75| < 5
 x: 140, y: 73}                Result: Success!
                       ←        Return: {verified: true}
```

## 🛡️ Security Analysis

### Attack Vectors Prevented

1. **Automated Solving**: Without knowing target position, bots cannot solve
2. **Replay Attacks**: Each challenge has unique ID and single use
3. **Brute Force**: Rate limiting and attempt limits prevent guessing
4. **Session Hijacking**: Challenge tied to session with expiration
5. **Position Disclosure**: Target coordinates never leave server

### Remaining Considerations

- **Image Analysis**: Advanced ML could analyze images to find matches
- **Mitigation**: Add noise, vary piece shapes, rotate pieces

## 📊 Performance Optimization

### Caching Strategy
- Cache generated images for slider mode (fixed Y position)
- Use Redis for distributed session storage
- Implement CDN for static assets

### Resource Usage
- Image generation: ~10-20ms per image
- Memory: ~500KB per active challenge
- Recommended: Clean expired challenges every 5 minutes

## 🧪 Testing

### Security Testing
```bash
# Attempt to solve without position (should fail)
curl -X POST http://localhost:8000/backend/php/secure-captcha.php?action=verify \
  -H "Content-Type: application/json" \
  -d '{"challengeId":"test","x":100,"y":100}'

# Test rate limiting (11th request should fail)
for i in {1..11}; do
  curl http://localhost:8000/backend/php/secure-captcha.php?action=challenge
done
```

### Load Testing
```bash
# Apache Bench - 100 requests, 10 concurrent
ab -n 100 -c 10 http://localhost:8000/backend/php/secure-captcha.php?action=challenge
```

## 📈 Metrics & Monitoring

### Key Metrics to Track
- Challenge generation rate
- Verification success/failure ratio
- Average solving time
- Rate limit violations
- Session expiration rate

### Recommended Logging
```php
error_log(json_encode([
    'event' => 'captcha_verification',
    'challenge_id' => $challengeId,
    'result' => $verified,
    'attempts' => $attempts,
    'solving_time' => $solvingTime,
    'ip' => $clientIp
]));
```

## 🚫 What NOT to Do

1. **Never send target position to client** - Even encrypted
2. **Never trust client-side validation** - Always verify server-side
3. **Never reuse challenge IDs** - One-time use only
4. **Never skip rate limiting** - Essential for security
5. **Never store positions in cookies** - Use server sessions

## 📝 License

MIT License - See LICENSE file for details

## 🤝 Contributing

Security improvements and bug reports are welcome. Please ensure any changes maintain the core security principle: **target positions must never be exposed to the client**.

---

*Last Updated: 2024*
*Security Level: Production Ready*