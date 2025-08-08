using System;
using System.Collections.Generic;
using System.Linq;
using System.Web;
using System.Web.Script.Serialization;
using System.Web.SessionState;
using System.Security.Cryptography;
using System.Text;
using System.IO;

namespace SliderCaptcha
{
    /// <summary>
    /// Secure Slider Captcha Implementation for .NET Framework 4.5
    /// IHttpHandler implementation for handling captcha requests
    /// </summary>
    public class SliderCaptchaHandler : IHttpHandler, IRequiresSessionState
    {
        private const int CHALLENGE_EXPIRY_MINUTES = 5;
        private const int MAX_ATTEMPTS = 5;
        private const int TOLERANCE = 10;
        private static readonly string SESSION_PATH = HttpContext.Current.Server.MapPath("~/App_Data/captcha_sessions/");
        
        public bool IsReusable => false;

        public void ProcessRequest(HttpContext context)
        {
            // Set CORS headers
            context.Response.Headers.Add("Access-Control-Allow-Origin", "*");
            context.Response.Headers.Add("Access-Control-Allow-Methods", "POST, GET, OPTIONS");
            context.Response.Headers.Add("Access-Control-Allow-Headers", "Content-Type");
            
            if (context.Request.HttpMethod == "OPTIONS")
            {
                context.Response.StatusCode = 204;
                return;
            }

            string action = context.Request.QueryString["action"];
            
            switch (action)
            {
                case "challenge":
                    GenerateChallenge(context);
                    break;
                case "verify":
                    VerifyChallenge(context);
                    break;
                case "cleanup":
                    CleanupOldChallenges(context);
                    break;
                default:
                    SendJsonResponse(context, new { error = "Invalid action" }, 404);
                    break;
            }
        }

        private void GenerateChallenge(HttpContext context)
        {
            // Generate unique challenge ID
            string challengeId = GenerateRandomString(64);
            
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
            
            // Store challenge in session
            var challengeData = new ChallengeData
            {
                Id = challengeId,
                TargetX = targetX,
                TargetSliderX = targetSliderX,
                Created = DateTime.UtcNow,
                Attempts = 0,
                Solved = false,
                SessionId = context.Session.SessionID,
                IpAddress = GetClientIp(context)
            };
            
            // Store in session
            context.Session["captcha_challenge"] = challengeData;
            
            // Also store in file for persistence
            StoreChallengeToFile(challengeId, challengeData);
            
            // Send response
            SendJsonResponse(context, new
            {
                challengeId = challengeId,
                targetX = targetX,
                timestamp = DateTimeOffset.UtcNow.ToUnixTimeSeconds()
            });
        }

        private void VerifyChallenge(HttpContext context)
        {
            if (context.Request.HttpMethod != "POST")
            {
                SendJsonResponse(context, new { verified = false, error = "Method not allowed" }, 405);
                return;
            }
            
            // Read request body
            string jsonString;
            using (StreamReader reader = new StreamReader(context.Request.InputStream))
            {
                jsonString = reader.ReadToEnd();
            }
            
            JavaScriptSerializer serializer = new JavaScriptSerializer();
            dynamic data = serializer.DeserializeObject(jsonString);
            
            if (data == null || !data.ContainsKey("challengeId") || !data.ContainsKey("trail"))
            {
                SendJsonResponse(context, new { verified = false, error = "Missing required fields" }, 400);
                return;
            }
            
            string challengeId = data["challengeId"];
            List<Dictionary<string, object>> trail = data["trail"];
            
            // Load challenge data
            ChallengeData challengeData = LoadChallengeFromFile(challengeId);
            
            if (challengeData == null)
            {
                SendJsonResponse(context, new { verified = false, error = "Invalid or expired challenge" });
                return;
            }
            
            // Check if already solved
            if (challengeData.Solved)
            {
                SendJsonResponse(context, new { verified = false, error = "Challenge already used" });
                return;
            }
            
            // Check expiration
            if ((DateTime.UtcNow - challengeData.Created).TotalMinutes > CHALLENGE_EXPIRY_MINUTES)
            {
                DeleteChallengeFile(challengeId);
                SendJsonResponse(context, new { verified = false, error = "Challenge expired" });
                return;
            }
            
            // Increment attempts
            challengeData.Attempts++;
            
            // Check max attempts
            if (challengeData.Attempts > MAX_ATTEMPTS)
            {
                DeleteChallengeFile(challengeId);
                SendJsonResponse(context, new { verified = false, error = "Too many attempts" });
                return;
            }
            
            // Update attempts
            StoreChallengeToFile(challengeId, challengeData);
            
            // Validate solution
            bool verified = ValidateSolution(trail, challengeData);
            
            if (verified)
            {
                // Mark as solved and delete
                challengeData.Solved = true;
                StoreChallengeToFile(challengeId, challengeData);
                DeleteChallengeFile(challengeId);
                
                // Clear session
                context.Session.Remove("captcha_challenge");
                
                // Generate success token
                string successToken = GenerateRandomString(32);
                context.Session["captcha_verified"] = new
                {
                    token = successToken,
                    timestamp = DateTime.UtcNow
                };
                
                SendJsonResponse(context, new
                {
                    verified = true,
                    message = "Verification successful",
                    token = successToken
                });
            }
            else
            {
                SendJsonResponse(context, new
                {
                    verified = false,
                    error = "Incorrect solution",
                    attemptsRemaining = MAX_ATTEMPTS - challengeData.Attempts
                });
            }
        }

        private bool ValidateSolution(List<Dictionary<string, object>> trail, ChallengeData challengeData)
        {
            if (trail == null || trail.Count < 3)
                return false;
            
            // Get final position
            var lastPoint = trail.Last();
            double finalX = Convert.ToDouble(lastPoint["x"]);
            
            // Check if slider reached target position
            if (Math.Abs(finalX - challengeData.TargetSliderX) > TOLERANCE)
                return false;
            
            // Check for forward movement
            var firstPoint = trail.First();
            double firstX = Convert.ToDouble(firstPoint["x"]);
            if (finalX <= firstX)
                return false;
            
            // Check duration (must take at least 100ms)
            double duration = Convert.ToDouble(lastPoint["t"]);
            if (duration < 100)
                return false;
            
            // Check for Y movement (human behavior)
            var yValues = trail.Select(p => Convert.ToDouble(p["y"])).ToList();
            double yRange = yValues.Max() - yValues.Min();
            if (yRange < 1)
                return false;
            
            return true;
        }

        private void CleanupOldChallenges(HttpContext context)
        {
            if (!Directory.Exists(SESSION_PATH))
            {
                Directory.CreateDirectory(SESSION_PATH);
            }
            
            var files = Directory.GetFiles(SESSION_PATH, "challenge_*.json");
            int cleaned = 0;
            
            foreach (var file in files)
            {
                try
                {
                    string json = File.ReadAllText(file);
                    JavaScriptSerializer serializer = new JavaScriptSerializer();
                    ChallengeData data = serializer.Deserialize<ChallengeData>(json);
                    
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
            
            SendJsonResponse(context, new { cleaned = cleaned });
        }

        private void StoreChallengeToFile(string challengeId, ChallengeData data)
        {
            if (!Directory.Exists(SESSION_PATH))
            {
                Directory.CreateDirectory(SESSION_PATH);
            }
            
            string filename = Path.Combine(SESSION_PATH, $"challenge_{challengeId}.json");
            JavaScriptSerializer serializer = new JavaScriptSerializer();
            string json = serializer.Serialize(data);
            File.WriteAllText(filename, json);
        }

        private ChallengeData LoadChallengeFromFile(string challengeId)
        {
            string filename = Path.Combine(SESSION_PATH, $"challenge_{challengeId}.json");
            
            if (!File.Exists(filename))
                return null;
            
            try
            {
                string json = File.ReadAllText(filename);
                JavaScriptSerializer serializer = new JavaScriptSerializer();
                return serializer.Deserialize<ChallengeData>(json);
            }
            catch
            {
                return null;
            }
        }

        private void DeleteChallengeFile(string challengeId)
        {
            string filename = Path.Combine(SESSION_PATH, $"challenge_{challengeId}.json");
            if (File.Exists(filename))
            {
                File.Delete(filename);
            }
        }

        private string GenerateRandomString(int length)
        {
            using (var rng = new RNGCryptoServiceProvider())
            {
                byte[] bytes = new byte[length / 2];
                rng.GetBytes(bytes);
                return BitConverter.ToString(bytes).Replace("-", "").ToLower();
            }
        }

        private string GetClientIp(HttpContext context)
        {
            string[] headers = { "HTTP_X_FORWARDED_FOR", "HTTP_X_REAL_IP", "REMOTE_ADDR" };
            
            foreach (string header in headers)
            {
                string ip = context.Request.ServerVariables[header];
                if (!string.IsNullOrEmpty(ip))
                {
                    if (ip.Contains(","))
                    {
                        ip = ip.Split(',')[0].Trim();
                    }
                    return ip;
                }
            }
            
            return context.Request.UserHostAddress ?? "0.0.0.0";
        }

        private void SendJsonResponse(HttpContext context, object data, int statusCode = 200)
        {
            context.Response.StatusCode = statusCode;
            context.Response.ContentType = "application/json";
            
            JavaScriptSerializer serializer = new JavaScriptSerializer();
            string json = serializer.Serialize(data);
            context.Response.Write(json);
        }

        private class ChallengeData
        {
            public string Id { get; set; }
            public int TargetX { get; set; }
            public double TargetSliderX { get; set; }
            public DateTime Created { get; set; }
            public int Attempts { get; set; }
            public bool Solved { get; set; }
            public string SessionId { get; set; }
            public string IpAddress { get; set; }
        }
    }
}