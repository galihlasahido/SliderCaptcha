using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading.Tasks;
using System.Web.Http;
using System.Web.Http.Cors;
using System.Security.Cryptography;
using System.Text;
using System.IO;
using Newtonsoft.Json;
using System.Collections.Concurrent;

namespace SliderCaptcha.Controllers
{
    /// <summary>
    /// Secure Slider Captcha Implementation for .NET Framework 4.8
    /// Web API 2 Controller with async/await support
    /// </summary>
    [RoutePrefix("api/slidercaptcha")]
    [EnableCors(origins: "*", headers: "*", methods: "*")]
    public class SliderCaptchaController : ApiController
    {
        private const int CHALLENGE_EXPIRY_MINUTES = 5;
        private const int MAX_ATTEMPTS = 5;
        private const int TOLERANCE = 10;
        private const int MIN_TRAIL_LENGTH = 3;
        private const int MIN_DURATION_MS = 100;
        
        // In-memory storage (use Redis in production)
        private static readonly ConcurrentDictionary<string, ChallengeData> ChallengeStore = 
            new ConcurrentDictionary<string, ChallengeData>();
        
        // File-based fallback storage
        private static readonly string SESSION_PATH = 
            System.Web.Hosting.HostingEnvironment.MapPath("~/App_Data/captcha_sessions/");

        /// <summary>
        /// Generate a new captcha challenge
        /// GET api/slidercaptcha/challenge
        /// </summary>
        [HttpGet]
        [Route("challenge")]
        public async Task<IHttpActionResult> GenerateChallenge()
        {
            return await Task.Run(() =>
            {
                // Generate unique challenge ID
                string challengeId = GenerateSecureToken(64);
                
                // Generate random puzzle position
                Random rand = new Random();
                int targetX = rand.Next(70, 250);
                
                // Calculate expected slider position
                int sliderL = 42;
                int sliderR = 9;
                int L = sliderL + sliderR * 2 + 3;
                int canvasWidth = 320;
                int maxSliderMove = canvasWidth - 40;
                int puzzleRange = canvasWidth - L;
                double targetSliderX = ((double)targetX / puzzleRange) * maxSliderMove;
                
                // Create challenge data
                var challengeData = new ChallengeData
                {
                    Id = challengeId,
                    TargetX = targetX,
                    TargetSliderX = targetSliderX,
                    Created = DateTime.UtcNow,
                    Attempts = 0,
                    Solved = false,
                    IpAddress = GetClientIpAddress()
                };
                
                // Store in memory
                ChallengeStore[challengeId] = challengeData;
                
                // Also persist to file (backup)
                _ = PersistChallengeAsync(challengeId, challengeData);
                
                // Clean up old challenges
                _ = Task.Run(() => CleanupExpiredChallenges());
                
                return Ok(new
                {
                    challengeId = challengeId,
                    targetX = targetX,
                    timestamp = DateTimeOffset.UtcNow.ToUnixTimeSeconds()
                });
            });
        }

        /// <summary>
        /// Verify captcha solution
        /// POST api/slidercaptcha/verify
        /// </summary>
        [HttpPost]
        [Route("verify")]
        public async Task<IHttpActionResult> VerifyChallenge([FromBody] VerificationRequest request)
        {
            if (request == null || string.IsNullOrEmpty(request.ChallengeId) || request.Trail == null)
            {
                return BadRequest(new { verified = false, error = "Missing required fields" });
            }
            
            // Load challenge from memory or file
            ChallengeData challengeData = await LoadChallengeAsync(request.ChallengeId);
            
            if (challengeData == null)
            {
                return Ok(new { verified = false, error = "Invalid or expired challenge" });
            }
            
            // Check if already solved
            if (challengeData.Solved)
            {
                return Ok(new { verified = false, error = "Challenge already used" });
            }
            
            // Check expiration
            if ((DateTime.UtcNow - challengeData.Created).TotalMinutes > CHALLENGE_EXPIRY_MINUTES)
            {
                await RemoveChallengeAsync(request.ChallengeId);
                return Ok(new { verified = false, error = "Challenge expired" });
            }
            
            // Increment attempts
            challengeData.Attempts++;
            
            // Check max attempts
            if (challengeData.Attempts > MAX_ATTEMPTS)
            {
                await RemoveChallengeAsync(request.ChallengeId);
                return Ok(new { verified = false, error = "Too many attempts" });
            }
            
            // Update attempts
            ChallengeStore[request.ChallengeId] = challengeData;
            await PersistChallengeAsync(request.ChallengeId, challengeData);
            
            // Validate solution
            bool verified = ValidateSolution(request.Trail, challengeData);
            
            if (verified)
            {
                // Mark as solved
                challengeData.Solved = true;
                ChallengeStore[request.ChallengeId] = challengeData;
                
                // Generate success token
                string successToken = GenerateSecureToken(32);
                
                // Remove challenge after successful verification
                await RemoveChallengeAsync(request.ChallengeId);
                
                return Ok(new
                {
                    verified = true,
                    message = "Verification successful",
                    token = successToken
                });
            }
            else
            {
                return Ok(new
                {
                    verified = false,
                    error = "Incorrect solution",
                    attemptsRemaining = MAX_ATTEMPTS - challengeData.Attempts
                });
            }
        }

        /// <summary>
        /// Clean up expired challenges
        /// GET api/slidercaptcha/cleanup
        /// </summary>
        [HttpGet]
        [Route("cleanup")]
        public async Task<IHttpActionResult> Cleanup()
        {
            int cleaned = await CleanupExpiredChallenges();
            return Ok(new { cleaned = cleaned });
        }

        /// <summary>
        /// Health check endpoint
        /// GET api/slidercaptcha/status
        /// </summary>
        [HttpGet]
        [Route("status")]
        public IHttpActionResult Status()
        {
            return Ok(new
            {
                status = "online",
                version = "2.0",
                framework = ".NET Framework 4.8",
                activeChallenges = ChallengeStore.Count,
                timestamp = DateTimeOffset.UtcNow.ToUnixTimeSeconds()
            });
        }

        #region Private Methods

        private bool ValidateSolution(List<TrailPoint> trail, ChallengeData challengeData)
        {
            if (trail == null || trail.Count < MIN_TRAIL_LENGTH)
                return false;
            
            // Get final position
            var lastPoint = trail.Last();
            double finalX = lastPoint.X;
            
            // Check if slider reached target position
            if (Math.Abs(finalX - challengeData.TargetSliderX) > TOLERANCE)
                return false;
            
            // Check for forward movement
            var firstPoint = trail.First();
            if (finalX <= firstPoint.X)
                return false;
            
            // Check duration
            if (lastPoint.T < MIN_DURATION_MS)
                return false;
            
            // Check for Y movement (human behavior)
            var yValues = trail.Select(p => p.Y).ToList();
            double yRange = yValues.Max() - yValues.Min();
            if (yRange < 1)
                return false;
            
            // Optional: Check for velocity patterns
            if (trail.Count > 10)
            {
                var velocities = CalculateVelocities(trail);
                double stdDev = CalculateStandardDeviation(velocities);
                
                // Bot detection: too uniform movement
                if (stdDev < 0.01)
                    return false;
            }
            
            return true;
        }

        private List<double> CalculateVelocities(List<TrailPoint> trail)
        {
            var velocities = new List<double>();
            
            for (int i = 1; i < trail.Count; i++)
            {
                double dx = trail[i].X - trail[i - 1].X;
                double dt = trail[i].T - trail[i - 1].T;
                
                if (dt > 0)
                {
                    velocities.Add(dx / dt);
                }
            }
            
            return velocities;
        }

        private double CalculateStandardDeviation(List<double> values)
        {
            if (values.Count == 0) return 0;
            
            double mean = values.Average();
            double sumSquaredDiffs = values.Sum(v => Math.Pow(v - mean, 2));
            double variance = sumSquaredDiffs / values.Count;
            
            return Math.Sqrt(variance);
        }

        private async Task<ChallengeData> LoadChallengeAsync(string challengeId)
        {
            // Try memory first
            if (ChallengeStore.TryGetValue(challengeId, out ChallengeData data))
            {
                return data;
            }
            
            // Try file storage
            return await LoadChallengeFromFileAsync(challengeId);
        }

        private async Task<ChallengeData> LoadChallengeFromFileAsync(string challengeId)
        {
            try
            {
                string filename = Path.Combine(SESSION_PATH, $"challenge_{challengeId}.json");
                
                if (!File.Exists(filename))
                    return null;
                
                string json = await Task.Run(() => File.ReadAllText(filename));
                return JsonConvert.DeserializeObject<ChallengeData>(json);
            }
            catch
            {
                return null;
            }
        }

        private async Task PersistChallengeAsync(string challengeId, ChallengeData data)
        {
            try
            {
                if (!Directory.Exists(SESSION_PATH))
                {
                    Directory.CreateDirectory(SESSION_PATH);
                }
                
                string filename = Path.Combine(SESSION_PATH, $"challenge_{challengeId}.json");
                string json = JsonConvert.SerializeObject(data);
                
                await Task.Run(() => File.WriteAllText(filename, json));
            }
            catch
            {
                // Log error in production
            }
        }

        private async Task RemoveChallengeAsync(string challengeId)
        {
            // Remove from memory
            ChallengeStore.TryRemove(challengeId, out _);
            
            // Remove from file storage
            await Task.Run(() =>
            {
                string filename = Path.Combine(SESSION_PATH, $"challenge_{challengeId}.json");
                if (File.Exists(filename))
                {
                    File.Delete(filename);
                }
            });
        }

        private async Task<int> CleanupExpiredChallenges()
        {
            int cleaned = 0;
            var expiredIds = new List<string>();
            
            // Clean memory storage
            foreach (var kvp in ChallengeStore)
            {
                if ((DateTime.UtcNow - kvp.Value.Created).TotalMinutes > CHALLENGE_EXPIRY_MINUTES)
                {
                    expiredIds.Add(kvp.Key);
                }
            }
            
            foreach (var id in expiredIds)
            {
                if (ChallengeStore.TryRemove(id, out _))
                {
                    cleaned++;
                }
            }
            
            // Clean file storage
            if (Directory.Exists(SESSION_PATH))
            {
                var files = Directory.GetFiles(SESSION_PATH, "challenge_*.json");
                
                foreach (var file in files)
                {
                    try
                    {
                        string json = await Task.Run(() => File.ReadAllText(file));
                        var data = JsonConvert.DeserializeObject<ChallengeData>(json);
                        
                        if ((DateTime.UtcNow - data.Created).TotalMinutes > CHALLENGE_EXPIRY_MINUTES)
                        {
                            File.Delete(file);
                            cleaned++;
                        }
                    }
                    catch
                    {
                        // Skip corrupted files
                    }
                }
            }
            
            return cleaned;
        }

        private string GenerateSecureToken(int length)
        {
            using (var rng = new RNGCryptoServiceProvider())
            {
                byte[] bytes = new byte[length / 2];
                rng.GetBytes(bytes);
                return BitConverter.ToString(bytes).Replace("-", "").ToLower();
            }
        }

        private string GetClientIpAddress()
        {
            if (Request.Properties.ContainsKey("MS_HttpContext"))
            {
                var ctx = Request.Properties["MS_HttpContext"] as System.Web.HttpContextWrapper;
                if (ctx != null)
                {
                    string ip = ctx.Request.UserHostAddress;
                    
                    // Check forwarded headers
                    string forwarded = ctx.Request.Headers["X-Forwarded-For"];
                    if (!string.IsNullOrEmpty(forwarded))
                    {
                        ip = forwarded.Split(',')[0].Trim();
                    }
                    
                    return ip;
                }
            }
            
            return "0.0.0.0";
        }

        #endregion

        #region Data Models

        public class ChallengeData
        {
            public string Id { get; set; }
            public int TargetX { get; set; }
            public double TargetSliderX { get; set; }
            public DateTime Created { get; set; }
            public int Attempts { get; set; }
            public bool Solved { get; set; }
            public string IpAddress { get; set; }
        }

        public class VerificationRequest
        {
            [JsonProperty("challengeId")]
            public string ChallengeId { get; set; }
            
            [JsonProperty("trail")]
            public List<TrailPoint> Trail { get; set; }
        }

        public class TrailPoint
        {
            [JsonProperty("x")]
            public double X { get; set; }
            
            [JsonProperty("y")]
            public double Y { get; set; }
            
            [JsonProperty("t")]
            public double T { get; set; }
        }

        #endregion
    }
}