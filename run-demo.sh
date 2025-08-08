#!/bin/bash

# Start PHP development server for Secure Slider Captcha Demo
# This script runs the demo on localhost:8000

echo "🚀 Starting Secure Slider Captcha Demo..."
echo "📁 Server root: $(pwd)"
echo ""
echo "🌐 Demo will be available at:"
echo "   http://localhost:8000/demos/"
echo ""
echo "📝 Direct links:"
echo "   Demo: http://localhost:8000/demos/index.php"
echo "   Backend: http://localhost:8000/backend/php/SliderCaptchaController-secure.php?action=status"
echo ""
echo "Press Ctrl+C to stop the server"
echo "----------------------------------------"

# Start PHP built-in server from project root
php -S localhost:8000