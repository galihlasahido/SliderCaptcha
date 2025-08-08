using System;
using System.Collections.Generic;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.Drawing.Imaging;
using System.IO;
using System.Linq;
using System.Security.Cryptography;
using System.Web;
using System.Web.SessionState;
using Newtonsoft.Json;

namespace SliderCaptcha
{
    /// <summary>
    /// Secure Slider Captcha Implementation for .NET
    /// Images are generated server-side, position never exposed to client
    /// </summary>
    public class SecureSliderCaptchaHandler : IHttpHandler, IRequiresSessionState
    {
        private const int ImageWidth = 320;
        private const int ImageHeight = 200;
        private const int PieceSize = 50;
        private const int Tolerance = 5;
        private const int MaxAttempts = 5;
        private const int ChallengeExpirationMinutes = 5;
        
        public bool IsReusable => false;
        
        public void ProcessRequest(HttpContext context)
        {
            context.Response.ContentType = "application/json";
            context.Response.Headers.Add("Access-Control-Allow-Origin", GetAllowedOrigin(context));
            context.Response.Headers.Add("Access-Control-Allow-Methods", "GET, POST, OPTIONS");
            context.Response.Headers.Add("Access-Control-Allow-Headers", "Content-Type");
            
            if (context.Request.HttpMethod == "OPTIONS")
            {
                context.Response.StatusCode = 200;
                return;
            }
            
            string action = context.Request.QueryString["action"];
            
            switch (action)
            {
                case "challenge":
                    GenerateChallenge(context);
                    break;
                case "background":
                    GenerateBackgroundImage(context);
                    break;
                case "piece":
                    GeneratePieceImage(context);
                    break;
                case "verify":
                    VerifySolution(context);
                    break;
                default:
                    context.Response.StatusCode = 404;
                    context.Response.Write(JsonConvert.SerializeObject(new { error = "Invalid action" }));
                    break;
            }
        }
        
        private void GenerateChallenge(HttpContext context)
        {
            // Check rate limiting
            if (!CheckRateLimit(context))
            {
                context.Response.StatusCode = 429;
                context.Response.Write(JsonConvert.SerializeObject(new { 
                    error = "Rate limit exceeded. Please try again later." 
                }));
                return;
            }
            
            string mode = context.Request.QueryString["mode"] ?? "freedrag";
            string challengeId = GenerateChallengeId();
            
            var random = new Random();
            int targetX = random.Next(60, ImageWidth - 60);
            int targetY = mode == "slider" ? 75 : random.Next(30, ImageHeight - 60);
            
            // Store challenge in session
            var challengeData = new ChallengeData
            {
                Id = challengeId,
                TargetX = targetX,
                TargetY = targetY,
                Created = DateTime.UtcNow,
                Attempts = 0,
                Solved = false
            };
            
            context.Session[$"captcha_{challengeId}"] = JsonConvert.SerializeObject(challengeData);
            
            // Return only non-sensitive data
            context.Response.Write(JsonConvert.SerializeObject(new
            {
                success = true,
                challengeId = challengeId,
                imageWidth = ImageWidth,
                imageHeight = ImageHeight,
                pieceSize = PieceSize
            }));
        }
        
        private void GenerateBackgroundImage(HttpContext context)
        {
            string challengeId = context.Request.QueryString["id"];
            if (string.IsNullOrEmpty(challengeId))
            {
                context.Response.StatusCode = 404;
                return;
            }
            
            var challengeData = GetChallengeData(context, challengeId);
            if (challengeData == null)
            {
                context.Response.StatusCode = 404;
                return;
            }
            
            using (var bitmap = new Bitmap(ImageWidth, ImageHeight))
            using (var graphics = Graphics.FromImage(bitmap))
            {
                graphics.SmoothingMode = SmoothingMode.AntiAlias;
                
                // Create gradient background
                using (var brush = new LinearGradientBrush(
                    new Point(0, 0),
                    new Point(ImageWidth, ImageHeight),
                    Color.FromArgb(100, 120, 140),
                    Color.FromArgb(150, 170, 190)))
                {
                    graphics.FillRectangle(brush, 0, 0, ImageWidth, ImageHeight);
                }
                
                // Add some texture
                var random = new Random(42); // Fixed seed for consistent texture
                for (int i = 0; i < 50; i++)
                {
                    int x = random.Next(ImageWidth);
                    int y = random.Next(ImageHeight);
                    int size = random.Next(5, 15);
                    var color = Color.FromArgb(50, 
                        random.Next(150, 200),
                        random.Next(150, 200),
                        random.Next(150, 200));
                    using (var brush = new SolidBrush(color))
                    {
                        graphics.FillEllipse(brush, x, y, size, size);
                    }
                }
                
                // Draw puzzle hole
                DrawPuzzleHole(graphics, challengeData.TargetX, challengeData.TargetY);
                
                // Output image
                context.Response.ContentType = "image/png";
                context.Response.Headers.Add("Cache-Control", "no-store, no-cache, must-revalidate");
                
                using (var ms = new MemoryStream())
                {
                    bitmap.Save(ms, ImageFormat.Png);
                    ms.WriteTo(context.Response.OutputStream);
                }
            }
        }
        
        private void GeneratePieceImage(HttpContext context)
        {
            string challengeId = context.Request.QueryString["id"];
            if (string.IsNullOrEmpty(challengeId))
            {
                context.Response.StatusCode = 404;
                return;
            }
            
            var challengeData = GetChallengeData(context, challengeId);
            if (challengeData == null)
            {
                context.Response.StatusCode = 404;
                return;
            }
            
            using (var bitmap = new Bitmap(PieceSize + 10, PieceSize))
            using (var graphics = Graphics.FromImage(bitmap))
            {
                graphics.SmoothingMode = SmoothingMode.AntiAlias;
                
                // Make background transparent
                bitmap.MakeTransparent();
                
                // Draw matching gradient for the piece
                int y = challengeData.TargetY;
                using (var brush = new LinearGradientBrush(
                    new Point(0, 0),
                    new Point(PieceSize, PieceSize),
                    Color.FromArgb(100 + y / 2, 120 + y / 3, 140 + y / 4),
                    Color.FromArgb(120 + y / 2, 140 + y / 3, 160 + y / 4)))
                {
                    graphics.FillRectangle(brush, 0, 0, PieceSize, PieceSize);
                }
                
                // Add matching texture
                var random = new Random(42); // Same seed as background
                for (int i = 0; i < 10; i++)
                {
                    int px = random.Next(PieceSize);
                    int py = random.Next(PieceSize);
                    int size = random.Next(5, 10);
                    var color = Color.FromArgb(100,
                        random.Next(150, 200),
                        random.Next(150, 200),
                        random.Next(150, 200));
                    using (var brush = new SolidBrush(color))
                    {
                        graphics.FillEllipse(brush, px, py, size, size);
                    }
                }
                
                // Draw puzzle tab on right
                DrawPuzzleTab(graphics, PieceSize, PieceSize / 2);
                
                // Draw border
                using (var pen = new Pen(Color.FromArgb(80, 80, 80), 2))
                {
                    graphics.DrawRectangle(pen, 0, 0, PieceSize - 1, PieceSize - 1);
                }
                
                // Output image
                context.Response.ContentType = "image/png";
                context.Response.Headers.Add("Cache-Control", "no-store, no-cache, must-revalidate");
                
                using (var ms = new MemoryStream())
                {
                    bitmap.Save(ms, ImageFormat.Png);
                    ms.WriteTo(context.Response.OutputStream);
                }
            }
        }
        
        private void VerifySolution(HttpContext context)
        {
            if (context.Request.HttpMethod != "POST")
            {
                context.Response.StatusCode = 405;
                return;
            }
            
            string requestBody;
            using (var reader = new StreamReader(context.Request.InputStream))
            {
                requestBody = reader.ReadToEnd();
            }
            
            var input = JsonConvert.DeserializeObject<VerifyRequest>(requestBody);
            
            if (input == null || string.IsNullOrEmpty(input.ChallengeId))
            {
                context.Response.Write(JsonConvert.SerializeObject(new { 
                    success = false, 
                    error = "Invalid request" 
                }));
                return;
            }
            
            var challengeData = GetChallengeData(context, input.ChallengeId);
            
            if (challengeData == null)
            {
                context.Response.Write(JsonConvert.SerializeObject(new { 
                    success = false, 
                    error = "Challenge not found" 
                }));
                return;
            }
            
            // Check expiration
            if ((DateTime.UtcNow - challengeData.Created).TotalMinutes > ChallengeExpirationMinutes)
            {
                RemoveChallenge(context, input.ChallengeId);
                context.Response.Write(JsonConvert.SerializeObject(new { 
                    success = false, 
                    error = "Challenge expired" 
                }));
                return;
            }
            
            // Check if already solved
            if (challengeData.Solved)
            {
                context.Response.Write(JsonConvert.SerializeObject(new { 
                    success = false, 
                    error = "Already solved" 
                }));
                return;
            }
            
            // Check attempts
            challengeData.Attempts++;
            if (challengeData.Attempts > MaxAttempts)
            {
                RemoveChallenge(context, input.ChallengeId);
                context.Response.Write(JsonConvert.SerializeObject(new { 
                    success = false, 
                    error = "Too many attempts" 
                }));
                return;
            }
            
            // Update challenge data
            context.Session[$"captcha_{input.ChallengeId}"] = JsonConvert.SerializeObject(challengeData);
            
            // Verify position
            bool correctX = Math.Abs(input.X - challengeData.TargetX) <= Tolerance;
            bool correctY = Math.Abs(input.Y - challengeData.TargetY) <= Tolerance;
            
            bool verified = correctX && correctY;
            
            if (verified)
            {
                challengeData.Solved = true;
                context.Session[$"captcha_{input.ChallengeId}"] = JsonConvert.SerializeObject(challengeData);
            }
            
            context.Response.Write(JsonConvert.SerializeObject(new
            {
                success = verified,
                verified = verified,
                attempts_left = MaxAttempts - challengeData.Attempts
            }));
        }
        
        private void DrawPuzzleHole(Graphics graphics, int x, int y)
        {
            // Draw white border
            using (var brush = new SolidBrush(Color.White))
            {
                graphics.FillRectangle(brush, x - 2, y - 2, PieceSize + 4, PieceSize + 4);
            }
            
            // Draw black hole
            using (var brush = new SolidBrush(Color.Black))
            {
                graphics.FillRectangle(brush, x, y, PieceSize, PieceSize);
            }
            
            // Draw puzzle tab hole on right
            using (var brush = new SolidBrush(Color.White))
            {
                graphics.FillEllipse(brush, x + PieceSize - 5, y + PieceSize / 2 - 10, 20, 20);
            }
            using (var brush = new SolidBrush(Color.Black))
            {
                graphics.FillEllipse(brush, x + PieceSize - 3, y + PieceSize / 2 - 8, 16, 16);
            }
        }
        
        private void DrawPuzzleTab(Graphics graphics, int x, int y)
        {
            using (var brush = new SolidBrush(Color.FromArgb(120, 140, 160)))
            {
                graphics.FillEllipse(brush, x - 8, y - 8, 16, 16);
            }
        }
        
        private string GenerateChallengeId()
        {
            using (var rng = new RNGCryptoServiceProvider())
            {
                byte[] bytes = new byte[16];
                rng.GetBytes(bytes);
                return BitConverter.ToString(bytes).Replace("-", "").ToLower();
            }
        }
        
        private ChallengeData GetChallengeData(HttpContext context, string challengeId)
        {
            var json = context.Session[$"captcha_{challengeId}"] as string;
            if (string.IsNullOrEmpty(json))
                return null;
            
            return JsonConvert.DeserializeObject<ChallengeData>(json);
        }
        
        private void RemoveChallenge(HttpContext context, string challengeId)
        {
            context.Session.Remove($"captcha_{challengeId}");
        }
        
        private bool CheckRateLimit(HttpContext context)
        {
            string ip = context.Request.UserHostAddress;
            string rateLimitKey = $"ratelimit_{ip}";
            
            var rateData = context.Session[rateLimitKey] as RateLimitData;
            var now = DateTime.UtcNow;
            
            if (rateData == null || (now - rateData.WindowStart).TotalSeconds > 60)
            {
                rateData = new RateLimitData { Count = 1, WindowStart = now };
            }
            else
            {
                if (rateData.Count >= 10)
                    return false;
                rateData.Count++;
            }
            
            context.Session[rateLimitKey] = rateData;
            return true;
        }
        
        private string GetAllowedOrigin(HttpContext context)
        {
            string origin = context.Request.Headers["Origin"];
            var allowedOrigins = new[] { "http://localhost:8000", "http://localhost:8080" };
            
            if (allowedOrigins.Contains(origin))
                return origin;
            
            return "http://localhost:8000";
        }
        
        private class ChallengeData
        {
            public string Id { get; set; }
            public int TargetX { get; set; }
            public int TargetY { get; set; }
            public DateTime Created { get; set; }
            public int Attempts { get; set; }
            public bool Solved { get; set; }
        }
        
        private class VerifyRequest
        {
            public string ChallengeId { get; set; }
            public double X { get; set; }
            public double Y { get; set; }
        }
        
        private class RateLimitData
        {
            public int Count { get; set; }
            public DateTime WindowStart { get; set; }
        }
    }
}