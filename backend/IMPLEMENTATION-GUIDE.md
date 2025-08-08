# Secure Slider Captcha - Backend Implementation Guide

## Overview
This guide covers the secure implementations of the slider captcha in PHP, .NET, and Java. All implementations follow the same security principles:

- **Position Never Exposed**: Target coordinates are stored server-side only
- **Server-Side Image Generation**: Puzzle images are generated dynamically
- **Session-Based Validation**: Each challenge is tied to a user session
- **Rate Limiting**: Prevents brute force attacks
- **Attempt Limiting**: Maximum 5 attempts per challenge

## 🔒 Security Features

| Feature | Description |
|---------|-------------|
| No Position Exposure | Target X,Y coordinates never sent to client |
| Server-Side Rendering | Images generated with GD/System.Drawing/Java AWT |
| Cryptographic IDs | Challenge IDs use secure random generation |
| Rate Limiting | 10 requests per minute per IP |
| Session Management | 5-minute challenge expiration |
| Input Validation | Strict validation of all inputs |

## 📁 File Structure

```
backend/
├── php/
│   └── secure-captcha.php                  # PHP implementation with GD
├── dotnet/
│   ├── SecureSliderCaptcha.cs              # .NET implementation
│   └── Web.config.example                  # IIS configuration example
├── java/
│   ├── SecureSliderCaptchaController.java  # Spring Boot controller
│   ├── pom.xml                             # Maven dependencies
│   └── application.properties              # Spring configuration
└── IMPLEMENTATION-GUIDE.md                 # This file
```

## 🚀 PHP Implementation

### Requirements
- PHP 7.4+ with GD library
- Session support enabled

### Setup
```bash
# Check if GD is installed
php -m | grep gd

# Run the server
php -S localhost:8000
```

### API Endpoints
```
GET  /secure-captcha.php?action=challenge&mode=slider|freedrag
GET  /secure-captcha.php?action=background&id={challengeId}
GET  /secure-captcha.php?action=piece&id={challengeId}
POST /secure-captcha.php?action=verify
```

### Example Usage
```javascript
// 1. Get challenge
fetch('/backend/php/secure-captcha.php?action=challenge&mode=slider')
  .then(res => res.json())
  .then(data => {
    // data = { challengeId, imageWidth, imageHeight, pieceSize }
    // Note: No targetX or targetY!
  });

// 2. Load images
backgroundImg.src = `/backend/php/secure-captcha.php?action=background&id=${challengeId}`;
pieceImg.src = `/backend/php/secure-captcha.php?action=piece&id=${challengeId}`;

// 3. Verify solution
fetch('/backend/php/secure-captcha.php?action=verify', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ challengeId, x: 100, y: 75 })
});
```

## 🔷 .NET Implementation

### Requirements
- .NET Framework 4.5+ or .NET Core 3.1+
- System.Drawing.Common package
- Newtonsoft.Json package

### Setup

#### For ASP.NET Web Application
1. Add `SecureSliderCaptcha.cs` to your project
2. Register the handler in `web.config`:

```xml
<system.web>
  <httpHandlers>
    <add verb="*" path="captcha.ashx" 
         type="SliderCaptcha.SecureSliderCaptchaHandler, YourAssembly"/>
  </httpHandlers>
</system.web>
```

#### For ASP.NET Core
```csharp
// In Startup.cs
public void Configure(IApplicationBuilder app)
{
    app.UseSession();
    app.Map("/api/captcha", captchaApp =>
    {
        captchaApp.Run(async context =>
        {
            var handler = new SecureSliderCaptchaHandler();
            await handler.ProcessRequestAsync(context);
        });
    });
}
```

### API Endpoints
```
GET  /captcha.ashx?action=challenge&mode=slider|freedrag
GET  /captcha.ashx?action=background&id={challengeId}
GET  /captcha.ashx?action=piece&id={challengeId}
POST /captcha.ashx?action=verify
```

## ☕ Java Spring Boot Implementation

### Requirements
- Java 11+
- Spring Boot 2.5+
- Spring Web, Spring Session

### Setup

#### Maven Dependencies
```xml
<dependencies>
    <dependency>
        <groupId>org.springframework.boot</groupId>
        <artifactId>spring-boot-starter-web</artifactId>
    </dependency>
    <dependency>
        <groupId>org.springframework.session</groupId>
        <artifactId>spring-session-core</artifactId>
    </dependency>
</dependencies>
```

#### Application Properties
```properties
# application.properties
server.port=8080
server.servlet.session.timeout=30m
spring.session.store-type=none
```

#### Controller Registration
```java
// In your main application class
@SpringBootApplication
@ComponentScan(basePackages = {"com.slidercaptcha"})
public class Application {
    public static void main(String[] args) {
        SpringApplication.run(Application.class, args);
    }
}
```

### API Endpoints
```
GET  /api/captcha/challenge?mode=slider|freedrag
GET  /api/captcha/background?id={challengeId}
GET  /api/captcha/piece?id={challengeId}
POST /api/captcha/verify
```

### Example Request
```java
// Using RestTemplate
RestTemplate restTemplate = new RestTemplate();

// Get challenge
Map<String, Object> challenge = restTemplate.getForObject(
    "http://localhost:8080/api/captcha/challenge?mode=slider",
    Map.class
);

// Verify solution
Map<String, Object> verifyData = Map.of(
    "challengeId", challenge.get("challengeId"),
    "x", 150.0,
    "y", 75.0
);

Map<String, Object> result = restTemplate.postForObject(
    "http://localhost:8080/api/captcha/verify",
    verifyData,
    Map.class
);
```

## 🔍 Security Comparison

### Traditional (Vulnerable) Approach
```json
// Client receives target position - VULNERABLE!
{
  "challengeId": "abc123",
  "targetX": 142,  // Exposed!
  "targetY": 75    // Exposed!
}
```

### Our Secure Approach
```json
// Client receives only non-sensitive data
{
  "challengeId": "xyz789",
  "imageWidth": 320,
  "imageHeight": 200,
  "pieceSize": 50
  // No position data!
}
```

## 🛡️ Security Best Practices

1. **Never expose target positions** - Store only in server session
2. **Use cryptographically secure random** - For challenge IDs and positions
3. **Implement rate limiting** - Prevent brute force attacks
4. **Validate all inputs** - Check types, ranges, and formats
5. **Set session timeouts** - Expire challenges after 5 minutes
6. **Limit attempts** - Maximum 5 attempts per challenge
7. **Use HTTPS in production** - Protect session cookies
8. **Configure CORS properly** - Restrict to your domains

## 📊 Performance Considerations

- **Image Generation**: Cache background images if position is fixed (slider mode)
- **Session Storage**: Use Redis or Memcached for distributed systems
- **Rate Limiting**: Use Redis for distributed rate limiting
- **Image Size**: Optimize PNG compression for faster loading

## 🧪 Testing

### Test Security
```bash
# Try to solve without knowing position (should fail)
curl -X POST http://localhost:8000/backend/php/secure-captcha.php?action=verify \
  -H "Content-Type: application/json" \
  -d '{"challengeId":"test","x":100,"y":100}'

# Try rate limiting (11th request should fail)
for i in {1..11}; do
  curl http://localhost:8000/backend/php/secure-captcha.php?action=challenge
done
```

### Test Image Generation
```bash
# Get challenge
CHALLENGE=$(curl -s http://localhost:8000/backend/php/secure-captcha.php?action=challenge | jq -r '.challengeId')

# Download images
curl -o background.png "http://localhost:8000/backend/php/secure-captcha.php?action=background&id=$CHALLENGE"
curl -o piece.png "http://localhost:8000/backend/php/secure-captcha.php?action=piece&id=$CHALLENGE"
```

## 📝 License
MIT License - See LICENSE file for details