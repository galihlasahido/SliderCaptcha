package com.slidercaptcha;

import org.springframework.web.bind.annotation.*;
import org.springframework.http.ResponseEntity;
import org.springframework.http.HttpStatus;
import org.springframework.http.MediaType;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.stereotype.Controller;

import javax.servlet.http.HttpSession;
import javax.servlet.http.HttpServletRequest;
import javax.imageio.ImageIO;
import java.awt.*;
import java.awt.image.BufferedImage;
import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.security.SecureRandom;
import java.util.*;
import java.util.concurrent.ConcurrentHashMap;
import java.time.LocalDateTime;
import java.time.temporal.ChronoUnit;

/**
 * Secure Slider Captcha Implementation for Spring Boot
 * Images are generated server-side, position never exposed to client
 */
@Controller
@RequestMapping("/api/captcha")
@CrossOrigin(origins = {"http://localhost:8000", "http://localhost:8080"})
public class SecureSliderCaptchaController {
    
    private static final int IMAGE_WIDTH = 320;
    private static final int IMAGE_HEIGHT = 200;
    private static final int PIECE_SIZE = 50;
    private static final int TOLERANCE = 5;
    private static final int MAX_ATTEMPTS = 5;
    private static final int CHALLENGE_EXPIRATION_MINUTES = 5;
    private static final int RATE_LIMIT_PER_MINUTE = 10;
    
    private final SecureRandom secureRandom = new SecureRandom();
    private final Map<String, RateLimitData> rateLimitMap = new ConcurrentHashMap<>();
    
    @Autowired
    private HttpSession session;
    
    /**
     * Generate a new captcha challenge
     */
    @GetMapping("/challenge")
    @ResponseBody
    public ResponseEntity<?> generateChallenge(
            @RequestParam(defaultValue = "freedrag") String mode,
            HttpServletRequest request) {
        
        // Check rate limiting
        if (!checkRateLimit(request.getRemoteAddr())) {
            return ResponseEntity.status(HttpStatus.TOO_MANY_REQUESTS)
                .body(Map.of("error", "Rate limit exceeded. Please try again later."));
        }
        
        String challengeId = generateChallengeId();
        
        // Generate random target position (kept secret on server)
        int targetX = secureRandom.nextInt(IMAGE_WIDTH - 120) + 60;
        int targetY = "slider".equals(mode) ? 75 : secureRandom.nextInt(IMAGE_HEIGHT - 90) + 30;
        
        // Store challenge data in session
        ChallengeData challengeData = new ChallengeData();
        challengeData.id = challengeId;
        challengeData.targetX = targetX;
        challengeData.targetY = targetY;
        challengeData.created = LocalDateTime.now();
        challengeData.attempts = 0;
        challengeData.solved = false;
        
        session.setAttribute("captcha_" + challengeId, challengeData);
        
        // Return only non-sensitive data
        Map<String, Object> response = new HashMap<>();
        response.put("success", true);
        response.put("challengeId", challengeId);
        response.put("imageWidth", IMAGE_WIDTH);
        response.put("imageHeight", IMAGE_HEIGHT);
        response.put("pieceSize", PIECE_SIZE);
        
        return ResponseEntity.ok(response);
    }
    
    /**
     * Generate background image with puzzle hole
     */
    @GetMapping(value = "/background", produces = MediaType.IMAGE_PNG_VALUE)
    @ResponseBody
    public ResponseEntity<byte[]> generateBackgroundImage(@RequestParam String id) {
        
        ChallengeData challengeData = getChallengeData(id);
        if (challengeData == null) {
            return ResponseEntity.notFound().build();
        }
        
        try {
            BufferedImage image = new BufferedImage(IMAGE_WIDTH, IMAGE_HEIGHT, BufferedImage.TYPE_INT_ARGB);
            Graphics2D g2d = image.createGraphics();
            g2d.setRenderingHint(RenderingHints.KEY_ANTIALIASING, RenderingHints.VALUE_ANTIALIAS_ON);
            
            // Create gradient background
            GradientPaint gradient = new GradientPaint(
                0, 0, new Color(100, 120, 140),
                IMAGE_WIDTH, IMAGE_HEIGHT, new Color(150, 170, 190)
            );
            g2d.setPaint(gradient);
            g2d.fillRect(0, 0, IMAGE_WIDTH, IMAGE_HEIGHT);
            
            // Add texture
            Random textureRandom = new Random(42); // Fixed seed for consistency
            for (int i = 0; i < 50; i++) {
                int x = textureRandom.nextInt(IMAGE_WIDTH);
                int y = textureRandom.nextInt(IMAGE_HEIGHT);
                int size = textureRandom.nextInt(10) + 5;
                Color color = new Color(
                    textureRandom.nextInt(50) + 150,
                    textureRandom.nextInt(50) + 150,
                    textureRandom.nextInt(50) + 150,
                    50
                );
                g2d.setColor(color);
                g2d.fillOval(x, y, size, size);
            }
            
            // Draw puzzle hole
            drawPuzzleHole(g2d, challengeData.targetX, challengeData.targetY);
            
            g2d.dispose();
            
            // Convert to byte array
            ByteArrayOutputStream baos = new ByteArrayOutputStream();
            ImageIO.write(image, "png", baos);
            byte[] imageBytes = baos.toByteArray();
            
            return ResponseEntity.ok()
                .header("Cache-Control", "no-store, no-cache, must-revalidate")
                .body(imageBytes);
                
        } catch (IOException e) {
            return ResponseEntity.status(HttpStatus.INTERNAL_SERVER_ERROR).build();
        }
    }
    
    /**
     * Generate puzzle piece image
     */
    @GetMapping(value = "/piece", produces = MediaType.IMAGE_PNG_VALUE)
    @ResponseBody
    public ResponseEntity<byte[]> generatePieceImage(@RequestParam String id) {
        
        ChallengeData challengeData = getChallengeData(id);
        if (challengeData == null) {
            return ResponseEntity.notFound().build();
        }
        
        try {
            BufferedImage image = new BufferedImage(PIECE_SIZE + 10, PIECE_SIZE, BufferedImage.TYPE_INT_ARGB);
            Graphics2D g2d = image.createGraphics();
            g2d.setRenderingHint(RenderingHints.KEY_ANTIALIASING, RenderingHints.VALUE_ANTIALIAS_ON);
            
            // Clear background (transparent)
            g2d.setComposite(AlphaComposite.Clear);
            g2d.fillRect(0, 0, PIECE_SIZE + 10, PIECE_SIZE);
            g2d.setComposite(AlphaComposite.SrcOver);
            
            // Draw matching gradient
            int y = challengeData.targetY;
            GradientPaint gradient = new GradientPaint(
                0, 0, new Color(100 + y/4, 120 + y/5, 140 + y/6),
                PIECE_SIZE, PIECE_SIZE, new Color(120 + y/4, 140 + y/5, 160 + y/6)
            );
            g2d.setPaint(gradient);
            g2d.fillRect(0, 0, PIECE_SIZE, PIECE_SIZE);
            
            // Add matching texture
            Random textureRandom = new Random(42); // Same seed as background
            for (int i = 0; i < 10; i++) {
                int px = textureRandom.nextInt(PIECE_SIZE);
                int py = textureRandom.nextInt(PIECE_SIZE);
                int size = textureRandom.nextInt(5) + 5;
                Color color = new Color(
                    textureRandom.nextInt(50) + 150,
                    textureRandom.nextInt(50) + 150,
                    textureRandom.nextInt(50) + 150,
                    100
                );
                g2d.setColor(color);
                g2d.fillOval(px, py, size, size);
            }
            
            // Draw puzzle tab on right
            g2d.setColor(new Color(120, 140, 160));
            g2d.fillOval(PIECE_SIZE - 8, PIECE_SIZE/2 - 8, 16, 16);
            
            // Draw border
            g2d.setColor(new Color(80, 80, 80));
            g2d.setStroke(new BasicStroke(2));
            g2d.drawRect(0, 0, PIECE_SIZE - 1, PIECE_SIZE - 1);
            
            g2d.dispose();
            
            // Convert to byte array
            ByteArrayOutputStream baos = new ByteArrayOutputStream();
            ImageIO.write(image, "png", baos);
            byte[] imageBytes = baos.toByteArray();
            
            return ResponseEntity.ok()
                .header("Cache-Control", "no-store, no-cache, must-revalidate")
                .body(imageBytes);
                
        } catch (IOException e) {
            return ResponseEntity.status(HttpStatus.INTERNAL_SERVER_ERROR).build();
        }
    }
    
    /**
     * Verify captcha solution
     */
    @PostMapping("/verify")
    @ResponseBody
    public ResponseEntity<?> verifySolution(@RequestBody VerifyRequest request) {
        
        if (request.challengeId == null || request.challengeId.isEmpty()) {
            return ResponseEntity.badRequest()
                .body(Map.of("success", false, "error", "Invalid request"));
        }
        
        ChallengeData challengeData = getChallengeData(request.challengeId);
        
        if (challengeData == null) {
            return ResponseEntity.ok()
                .body(Map.of("success", false, "error", "Challenge not found"));
        }
        
        // Check expiration
        if (ChronoUnit.MINUTES.between(challengeData.created, LocalDateTime.now()) > CHALLENGE_EXPIRATION_MINUTES) {
            removeChallenge(request.challengeId);
            return ResponseEntity.ok()
                .body(Map.of("success", false, "error", "Challenge expired"));
        }
        
        // Check if already solved
        if (challengeData.solved) {
            return ResponseEntity.ok()
                .body(Map.of("success", false, "error", "Already solved"));
        }
        
        // Check attempts
        challengeData.attempts++;
        if (challengeData.attempts > MAX_ATTEMPTS) {
            removeChallenge(request.challengeId);
            return ResponseEntity.ok()
                .body(Map.of("success", false, "error", "Too many attempts"));
        }
        
        // Update challenge data
        session.setAttribute("captcha_" + request.challengeId, challengeData);
        
        // Verify position
        boolean correctX = Math.abs(request.x - challengeData.targetX) <= TOLERANCE;
        boolean correctY = Math.abs(request.y - challengeData.targetY) <= TOLERANCE;
        
        boolean verified = correctX && correctY;
        
        if (verified) {
            challengeData.solved = true;
            session.setAttribute("captcha_" + request.challengeId, challengeData);
        }
        
        Map<String, Object> response = new HashMap<>();
        response.put("success", verified);
        response.put("verified", verified);
        response.put("attempts_left", MAX_ATTEMPTS - challengeData.attempts);
        
        return ResponseEntity.ok(response);
    }
    
    private void drawPuzzleHole(Graphics2D g2d, int x, int y) {
        // Draw white border
        g2d.setColor(Color.WHITE);
        g2d.fillRect(x - 2, y - 2, PIECE_SIZE + 4, PIECE_SIZE + 4);
        
        // Draw black hole
        g2d.setColor(Color.BLACK);
        g2d.fillRect(x, y, PIECE_SIZE, PIECE_SIZE);
        
        // Draw puzzle tab hole on right
        g2d.setColor(Color.WHITE);
        g2d.fillOval(x + PIECE_SIZE - 5, y + PIECE_SIZE/2 - 10, 20, 20);
        g2d.setColor(Color.BLACK);
        g2d.fillOval(x + PIECE_SIZE - 3, y + PIECE_SIZE/2 - 8, 16, 16);
    }
    
    private String generateChallengeId() {
        byte[] bytes = new byte[16];
        secureRandom.nextBytes(bytes);
        StringBuilder sb = new StringBuilder();
        for (byte b : bytes) {
            sb.append(String.format("%02x", b));
        }
        return sb.toString();
    }
    
    private ChallengeData getChallengeData(String challengeId) {
        return (ChallengeData) session.getAttribute("captcha_" + challengeId);
    }
    
    private void removeChallenge(String challengeId) {
        session.removeAttribute("captcha_" + challengeId);
    }
    
    private boolean checkRateLimit(String ip) {
        LocalDateTime now = LocalDateTime.now();
        
        // Clean old entries
        rateLimitMap.entrySet().removeIf(entry -> 
            ChronoUnit.MINUTES.between(entry.getValue().windowStart, now) > 1);
        
        RateLimitData rateData = rateLimitMap.get(ip);
        
        if (rateData == null || ChronoUnit.MINUTES.between(rateData.windowStart, now) > 1) {
            rateData = new RateLimitData();
            rateData.count = 1;
            rateData.windowStart = now;
            rateLimitMap.put(ip, rateData);
            return true;
        }
        
        if (rateData.count >= RATE_LIMIT_PER_MINUTE) {
            return false;
        }
        
        rateData.count++;
        return true;
    }
    
    // Data classes
    private static class ChallengeData {
        String id;
        int targetX;
        int targetY;
        LocalDateTime created;
        int attempts;
        boolean solved;
    }
    
    private static class RateLimitData {
        int count;
        LocalDateTime windowStart;
    }
    
    public static class VerifyRequest {
        private String challengeId;
        private double x;
        private double y;
        
        // Getters and setters
        public String getChallengeId() { return challengeId; }
        public void setChallengeId(String challengeId) { this.challengeId = challengeId; }
        public double getX() { return x; }
        public void setX(double x) { this.x = x; }
        public double getY() { return y; }
        public void setY(double y) { this.y = y; }
    }
}