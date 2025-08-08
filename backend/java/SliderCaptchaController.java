package com.example.slidercaptcha.controller;

import org.springframework.web.bind.annotation.*;
import org.springframework.http.ResponseEntity;
import org.springframework.http.HttpStatus;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Component;
import org.springframework.scheduling.annotation.Scheduled;
import org.springframework.scheduling.annotation.EnableScheduling;

import javax.servlet.http.HttpSession;
import javax.servlet.http.HttpServletRequest;
import java.security.SecureRandom;
import java.time.Instant;
import java.time.Duration;
import java.util.*;
import java.util.concurrent.ConcurrentHashMap;
import java.util.stream.Collectors;
import java.io.*;
import java.nio.file.*;

import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.annotation.JsonProperty;
import lombok.Data;
import lombok.extern.slf4j.Slf4j;

/**
 * Secure Slider Captcha Implementation for Spring Boot
 * Provides challenge-response based captcha validation with anti-replay protection
 * 
 * @author SliderCaptcha
 * @version 3.0
 */
@Slf4j
@RestController
@RequestMapping("/api/slidercaptcha")
@CrossOrigin(origins = "*", allowedHeaders = "*")
@EnableScheduling
public class SliderCaptchaController {
    
    private static final int CHALLENGE_EXPIRY_MINUTES = 5;
    private static final int MAX_ATTEMPTS = 5;
    private static final int TOLERANCE = 10;
    private static final int MIN_TRAIL_LENGTH = 3;
    private static final int MIN_DURATION_MS = 100;
    private static final int CANVAS_WIDTH = 320;
    private static final int SLIDER_L = 42;
    private static final int SLIDER_R = 9;
    
    // In-memory storage (use Redis in production)
    private final Map<String, ChallengeData> challengeStore = new ConcurrentHashMap<>();
    
    @Value("${captcha.storage.path:/tmp/captcha_sessions/}")
    private String storagePath;
    
    private final ObjectMapper objectMapper = new ObjectMapper();
    private final SecureRandom secureRandom = new SecureRandom();
    
    /**
     * Generate a new captcha challenge
     */
    @GetMapping("/challenge")
    public ResponseEntity<Map<String, Object>> generateChallenge(HttpSession session, 
                                                                 HttpServletRequest request) {
        try {
            // Generate unique challenge ID
            String challengeId = generateSecureToken(64);
            
            // Generate random puzzle position
            int targetX = 70 + secureRandom.nextInt(181); // 70 to 250
            
            // Calculate expected slider position
            int L = SLIDER_L + SLIDER_R * 2 + 3;
            int maxSliderMove = CANVAS_WIDTH - 40;
            int puzzleRange = CANVAS_WIDTH - L;
            double targetSliderX = ((double) targetX / puzzleRange) * maxSliderMove;
            
            // Create challenge data
            ChallengeData challengeData = new ChallengeData();
            challengeData.setId(challengeId);
            challengeData.setTargetX(targetX);
            challengeData.setTargetSliderX(targetSliderX);
            challengeData.setCreated(Instant.now());
            challengeData.setAttempts(0);
            challengeData.setSolved(false);
            challengeData.setSessionId(session.getId());
            challengeData.setIpAddress(getClientIpAddress(request));
            
            // Store in memory
            challengeStore.put(challengeId, challengeData);
            
            // Store in session
            session.setAttribute("captcha_challenge", challengeData);
            
            // Persist to file (async)
            persistChallengeAsync(challengeId, challengeData);
            
            log.info("Generated challenge: {} for IP: {}", challengeId, challengeData.getIpAddress());
            
            // Return response
            Map<String, Object> response = new HashMap<>();
            response.put("challengeId", challengeId);
            response.put("targetX", targetX);
            response.put("timestamp", Instant.now().getEpochSecond());
            
            return ResponseEntity.ok(response);
            
        } catch (Exception e) {
            log.error("Error generating challenge", e);
            return ResponseEntity.status(HttpStatus.INTERNAL_SERVER_ERROR)
                    .body(Map.of("error", "Failed to generate challenge"));
        }
    }
    
    /**
     * Verify captcha solution
     */
    @PostMapping("/verify")
    public ResponseEntity<Map<String, Object>> verifyChallenge(
            @RequestBody VerificationRequest request,
            HttpSession session) {
        
        Map<String, Object> response = new HashMap<>();
        
        // Validate request
        if (request == null || request.getChallengeId() == null || request.getTrail() == null) {
            response.put("verified", false);
            response.put("error", "Missing required fields");
            return ResponseEntity.badRequest().body(response);
        }
        
        try {
            // Load challenge
            ChallengeData challengeData = loadChallenge(request.getChallengeId());
            
            if (challengeData == null) {
                log.warn("Invalid challenge ID: {}", request.getChallengeId());
                response.put("verified", false);
                response.put("error", "Invalid or expired challenge");
                return ResponseEntity.ok(response);
            }
            
            // Check if already solved
            if (challengeData.isSolved()) {
                log.warn("Challenge already used: {}", request.getChallengeId());
                response.put("verified", false);
                response.put("error", "Challenge already used");
                return ResponseEntity.ok(response);
            }
            
            // Check expiration
            Duration age = Duration.between(challengeData.getCreated(), Instant.now());
            if (age.toMinutes() > CHALLENGE_EXPIRY_MINUTES) {
                removeChallenge(request.getChallengeId());
                log.warn("Challenge expired: {}", request.getChallengeId());
                response.put("verified", false);
                response.put("error", "Challenge expired");
                return ResponseEntity.ok(response);
            }
            
            // Increment attempts
            challengeData.setAttempts(challengeData.getAttempts() + 1);
            
            // Check max attempts
            if (challengeData.getAttempts() > MAX_ATTEMPTS) {
                removeChallenge(request.getChallengeId());
                log.warn("Too many attempts for challenge: {}", request.getChallengeId());
                response.put("verified", false);
                response.put("error", "Too many attempts");
                return ResponseEntity.ok(response);
            }
            
            // Update challenge
            challengeStore.put(request.getChallengeId(), challengeData);
            persistChallengeAsync(request.getChallengeId(), challengeData);
            
            // Validate solution
            boolean verified = validateSolution(request.getTrail(), challengeData);
            
            if (verified) {
                // Mark as solved
                challengeData.setSolved(true);
                challengeStore.put(request.getChallengeId(), challengeData);
                
                // Generate success token
                String successToken = generateSecureToken(32);
                
                // Store verification in session
                session.setAttribute("captcha_verified", Map.of(
                    "token", successToken,
                    "timestamp", Instant.now()
                ));
                
                // Remove challenge
                removeChallenge(request.getChallengeId());
                
                log.info("Challenge verified successfully: {}", request.getChallengeId());
                
                response.put("verified", true);
                response.put("message", "Verification successful");
                response.put("token", successToken);
                
            } else {
                log.info("Challenge verification failed: {}", request.getChallengeId());
                
                response.put("verified", false);
                response.put("error", "Incorrect solution");
                response.put("attemptsRemaining", MAX_ATTEMPTS - challengeData.getAttempts());
            }
            
            return ResponseEntity.ok(response);
            
        } catch (Exception e) {
            log.error("Error verifying challenge", e);
            response.put("verified", false);
            response.put("error", "Verification error");
            return ResponseEntity.status(HttpStatus.INTERNAL_SERVER_ERROR).body(response);
        }
    }
    
    /**
     * Clean up expired challenges
     */
    @GetMapping("/cleanup")
    public ResponseEntity<Map<String, Object>> cleanup() {
        int cleaned = cleanupExpiredChallenges();
        return ResponseEntity.ok(Map.of("cleaned", cleaned));
    }
    
    /**
     * Health check endpoint
     */
    @GetMapping("/status")
    public ResponseEntity<Map<String, Object>> status() {
        Map<String, Object> status = new HashMap<>();
        status.put("status", "online");
        status.put("version", "3.0");
        status.put("framework", "Spring Boot");
        status.put("activeChallenges", challengeStore.size());
        status.put("timestamp", Instant.now().getEpochSecond());
        
        return ResponseEntity.ok(status);
    }
    
    /**
     * Scheduled task to clean up expired challenges
     */
    @Scheduled(fixedDelay = 60000) // Run every minute
    public void scheduledCleanup() {
        int cleaned = cleanupExpiredChallenges();
        if (cleaned > 0) {
            log.info("Cleaned up {} expired challenges", cleaned);
        }
    }
    
    // Private methods
    
    private boolean validateSolution(List<TrailPoint> trail, ChallengeData challengeData) {
        if (trail == null || trail.size() < MIN_TRAIL_LENGTH) {
            log.debug("Trail too short: {}", trail != null ? trail.size() : 0);
            return false;
        }
        
        // Get final position
        TrailPoint lastPoint = trail.get(trail.size() - 1);
        double finalX = lastPoint.getX();
        
        // Check if slider reached target position
        if (Math.abs(finalX - challengeData.getTargetSliderX()) > TOLERANCE) {
            log.debug("Position mismatch - Expected: {}, Got: {}", 
                     challengeData.getTargetSliderX(), finalX);
            return false;
        }
        
        // Check for forward movement
        TrailPoint firstPoint = trail.get(0);
        if (finalX <= firstPoint.getX()) {
            log.debug("No forward movement detected");
            return false;
        }
        
        // Check duration
        if (lastPoint.getT() < MIN_DURATION_MS) {
            log.debug("Movement too fast: {}ms", lastPoint.getT());
            return false;
        }
        
        // Check for Y movement (human behavior)
        List<Double> yValues = trail.stream()
                .map(TrailPoint::getY)
                .collect(Collectors.toList());
        
        double yMin = Collections.min(yValues);
        double yMax = Collections.max(yValues);
        double yRange = yMax - yMin;
        
        if (yRange < 1) {
            log.debug("No Y-axis movement detected (bot-like)");
            return false;
        }
        
        // Optional: Check velocity patterns for bot detection
        if (trail.size() > 10) {
            List<Double> velocities = calculateVelocities(trail);
            double stdDev = calculateStandardDeviation(velocities);
            
            if (stdDev < 0.01) {
                log.debug("Uniform velocity detected (bot-like)");
                return false;
            }
        }
        
        return true;
    }
    
    private List<Double> calculateVelocities(List<TrailPoint> trail) {
        List<Double> velocities = new ArrayList<>();
        
        for (int i = 1; i < trail.size(); i++) {
            double dx = trail.get(i).getX() - trail.get(i - 1).getX();
            double dt = trail.get(i).getT() - trail.get(i - 1).getT();
            
            if (dt > 0) {
                velocities.add(dx / dt);
            }
        }
        
        return velocities;
    }
    
    private double calculateStandardDeviation(List<Double> values) {
        if (values.isEmpty()) return 0;
        
        double mean = values.stream()
                .mapToDouble(Double::doubleValue)
                .average()
                .orElse(0);
        
        double variance = values.stream()
                .mapToDouble(v -> Math.pow(v - mean, 2))
                .average()
                .orElse(0);
        
        return Math.sqrt(variance);
    }
    
    private ChallengeData loadChallenge(String challengeId) {
        // Try memory first
        ChallengeData data = challengeStore.get(challengeId);
        if (data != null) {
            return data;
        }
        
        // Try file storage
        return loadChallengeFromFile(challengeId);
    }
    
    private ChallengeData loadChallengeFromFile(String challengeId) {
        try {
            Path file = Paths.get(storagePath, "challenge_" + challengeId + ".json");
            if (!Files.exists(file)) {
                return null;
            }
            
            String json = Files.readString(file);
            return objectMapper.readValue(json, ChallengeData.class);
            
        } catch (Exception e) {
            log.error("Error loading challenge from file", e);
            return null;
        }
    }
    
    private void persistChallengeAsync(String challengeId, ChallengeData data) {
        new Thread(() -> {
            try {
                Files.createDirectories(Paths.get(storagePath));
                Path file = Paths.get(storagePath, "challenge_" + challengeId + ".json");
                String json = objectMapper.writeValueAsString(data);
                Files.writeString(file, json);
            } catch (Exception e) {
                log.error("Error persisting challenge", e);
            }
        }).start();
    }
    
    private void removeChallenge(String challengeId) {
        // Remove from memory
        challengeStore.remove(challengeId);
        
        // Remove from file storage
        try {
            Path file = Paths.get(storagePath, "challenge_" + challengeId + ".json");
            Files.deleteIfExists(file);
        } catch (Exception e) {
            log.error("Error removing challenge file", e);
        }
    }
    
    private int cleanupExpiredChallenges() {
        int cleaned = 0;
        Instant cutoff = Instant.now().minus(Duration.ofMinutes(CHALLENGE_EXPIRY_MINUTES));
        
        // Clean memory storage
        List<String> expiredIds = challengeStore.entrySet().stream()
                .filter(e -> e.getValue().getCreated().isBefore(cutoff))
                .map(Map.Entry::getKey)
                .collect(Collectors.toList());
        
        for (String id : expiredIds) {
            challengeStore.remove(id);
            cleaned++;
        }
        
        // Clean file storage
        try {
            Files.createDirectories(Paths.get(storagePath));
            try (DirectoryStream<Path> stream = Files.newDirectoryStream(
                    Paths.get(storagePath), "challenge_*.json")) {
                
                for (Path file : stream) {
                    try {
                        String json = Files.readString(file);
                        ChallengeData data = objectMapper.readValue(json, ChallengeData.class);
                        
                        if (data.getCreated().isBefore(cutoff)) {
                            Files.delete(file);
                            cleaned++;
                        }
                    } catch (Exception e) {
                        // Skip corrupted files
                    }
                }
            }
        } catch (Exception e) {
            log.error("Error cleaning up files", e);
        }
        
        return cleaned;
    }
    
    private String generateSecureToken(int length) {
        byte[] bytes = new byte[length / 2];
        secureRandom.nextBytes(bytes);
        
        StringBuilder sb = new StringBuilder();
        for (byte b : bytes) {
            sb.append(String.format("%02x", b));
        }
        
        return sb.toString();
    }
    
    private String getClientIpAddress(HttpServletRequest request) {
        String[] headers = {
            "X-Forwarded-For",
            "X-Real-IP",
            "Proxy-Client-IP",
            "WL-Proxy-Client-IP"
        };
        
        for (String header : headers) {
            String ip = request.getHeader(header);
            if (ip != null && !ip.isEmpty() && !"unknown".equalsIgnoreCase(ip)) {
                // Handle comma-separated IPs
                if (ip.contains(",")) {
                    ip = ip.split(",")[0].trim();
                }
                return ip;
            }
        }
        
        return request.getRemoteAddr();
    }
    
    // Data models
    
    @Data
    public static class ChallengeData {
        private String id;
        private int targetX;
        private double targetSliderX;
        private Instant created;
        private int attempts;
        private boolean solved;
        private String sessionId;
        private String ipAddress;
    }
    
    @Data
    public static class VerificationRequest {
        @JsonProperty("challengeId")
        private String challengeId;
        
        @JsonProperty("trail")
        private List<TrailPoint> trail;
    }
    
    @Data
    public static class TrailPoint {
        @JsonProperty("x")
        private double x;
        
        @JsonProperty("y")
        private double y;
        
        @JsonProperty("t")
        private double t;
    }
}