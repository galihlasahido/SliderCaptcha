class SecureSliderCaptcha {
    constructor(element, options = {}) {
        this.element = typeof element === 'string' ? 
            document.querySelector(element) : element;
        
        if (!this.element) {
            throw new Error('Element not found');
        }

        this.options = {
            width: 320,
            height: 160,
            sliderL: 42,
            sliderR: 9,
            offset: 10,
            loadingText: 'Loading...',
            failedText: 'Try again',
            barText: 'Slide to complete puzzle',
            repeatIcon: 'fa fa-repeat',
            localImages: () => `../frontend/images/Pic${Math.round(Math.random() * 4)}.jpg`,
            challengeUrl: 'SliderCaptchaController-secure.php?action=challenge',
            verifyUrl: 'SliderCaptchaController-secure.php?action=verify',
            onSuccess: null,
            onFail: null,
            onRefresh: null,
            ...options
        };

        this.challengeId = null;
        this.targetX = null;
        this.targetSliderX = null;
        this.trail = [];
        this.init();
    }

    async init() {
        this.setupContainer();
        this.createDOM();
        await this.requestChallenge();
    }

    setupContainer() {
        this.element.style.position = 'relative';
        this.element.style.width = this.options.width + 'px';
        this.element.style.margin = '0 auto';
        this.element.style.userSelect = 'none';
    }

    createDOM() {
        this.element.innerHTML = `
            <div class="sc-captcha-box" style="position: relative;">
                <canvas class="sc-canvas"></canvas>
                <canvas class="sc-block"></canvas>
                <i class="sc-refresh ${this.options.repeatIcon}"></i>
            </div>
            <div class="sc-slider-container">
                <div class="sc-slider-bg"></div>
                <div class="sc-slider-mask">
                    <div class="sc-slider">
                        <i class="fa fa-arrow-right sc-slider-icon"></i>
                    </div>
                </div>
                <span class="sc-slider-text">${this.options.loadingText}</span>
            </div>
        `;
        
        this.canvas = this.element.querySelector('.sc-canvas');
        this.block = this.element.querySelector('.sc-block');
        this.refreshIcon = this.element.querySelector('.sc-refresh');
        this.sliderContainer = this.element.querySelector('.sc-slider-container');
        this.slider = this.element.querySelector('.sc-slider');
        this.sliderMask = this.element.querySelector('.sc-slider-mask');
        this.sliderText = this.element.querySelector('.sc-slider-text');
        
        this.canvas.width = this.options.width;
        this.canvas.height = this.options.height;
        
        this.canvasCtx = this.canvas.getContext('2d');
        
        // Bind refresh button early
        this.refreshIcon.addEventListener('click', () => {
            this.reset();
            if (this.options.onRefresh) {
                this.options.onRefresh.call(this);
            }
        });
    }

    async requestChallenge() {
        try {
            console.log('Requesting new challenge from server...');
            
            const response = await fetch(this.options.challengeUrl, {
                method: 'GET',
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                throw new Error('Failed to get challenge');
            }
            
            const data = await response.json();
            
            this.challengeId = data.challengeId;
            this.targetX = data.targetX;
            
            // Calculate expected slider position
            this.L = this.options.sliderL + this.options.sliderR * 2 + 3;
            const maxSliderMove = this.options.width - 40;
            const puzzleRange = this.options.width - this.L;
            this.targetSliderX = (this.targetX / puzzleRange) * maxSliderMove;
            
            console.log('Challenge received:', {
                challengeId: this.challengeId,
                targetX: this.targetX,
                targetSliderX: this.targetSliderX
            });
            
            // Now load the image with the server-determined position
            this.loadImage();
            
        } catch (error) {
            console.error('Failed to get challenge:', error);
            this.sliderText.textContent = 'Failed to load captcha';
            this.sliderContainer.classList.add('sc-error');
        }
    }

    loadImage() {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        
        img.onload = () => {
            // Use server-provided position
            this.puzzleY = this.getRandomNumber(
                10 + this.options.sliderR * 2, 
                this.options.height - this.L - 10
            );
            
            console.log('Drawing puzzle at server position:', {
                targetX: this.targetX,
                puzzleY: this.puzzleY
            });
            
            this.drawPuzzle(img);
            this.sliderText.textContent = this.options.barText;
            this.bindEvents();
        };
        
        img.onerror = () => {
            img.src = this.options.localImages();
        };
        
        img.src = this.options.localImages();
        this.img = img;
    }

    drawPuzzle(img) {
        const { sliderL: l, sliderR: r } = this.options;
        const PI = Math.PI;
        
        // Clear main canvas
        this.canvasCtx.clearRect(0, 0, this.canvas.width, this.canvas.height);
        
        // Draw the main image
        this.canvasCtx.drawImage(img, 0, 0, this.options.width, this.options.height);
        
        // Create puzzle piece path function
        const createPuzzlePath = (ctx, x, y) => {
            ctx.beginPath();
            ctx.moveTo(x, y);
            ctx.arc(x + l / 2, y - r + 2, r, 0.72 * PI, 2.26 * PI);
            ctx.lineTo(x + l, y);
            ctx.arc(x + l + r - 2, y + l / 2, r, 1.21 * PI, 2.78 * PI);
            ctx.lineTo(x + l, y + l);
            ctx.lineTo(x, y + l);
            ctx.arc(x + r - 2, y + l / 2, r + 0.4, 2.76 * PI, 1.24 * PI, true);
            ctx.lineTo(x, y);
            ctx.closePath();
        };
        
        // Cut out at server-determined position
        this.canvasCtx.save();
        createPuzzlePath(this.canvasCtx, this.targetX, this.puzzleY);
        this.canvasCtx.globalCompositeOperation = 'destination-out';
        this.canvasCtx.fill();
        this.canvasCtx.restore();
        
        // Draw outline
        this.canvasCtx.save();
        createPuzzlePath(this.canvasCtx, this.targetX, this.puzzleY);
        this.canvasCtx.strokeStyle = 'rgba(255, 255, 255, 0.5)';
        this.canvasCtx.stroke();
        this.canvasCtx.restore();
        
        // Create the movable puzzle piece
        const blockExtraHeight = r + 10;
        this.block.width = this.L;
        this.block.height = this.L + blockExtraHeight;
        this.blockCtx = this.block.getContext('2d');
        
        // Extract puzzle piece
        const tempCanvas = document.createElement('canvas');
        tempCanvas.width = this.L;
        tempCanvas.height = this.L + blockExtraHeight;
        const tempCtx = tempCanvas.getContext('2d');
        
        tempCtx.drawImage(
            img,
            this.targetX,
            this.puzzleY - r - 10,  // Match the extra height offset
            this.L,
            this.L + blockExtraHeight,
            0,
            0,
            this.L,
            this.L + blockExtraHeight
        );
        
        // Apply clipping mask
        this.blockCtx.clearRect(0, 0, this.L, this.L + blockExtraHeight);
        this.blockCtx.save();
        createPuzzlePath(this.blockCtx, 0, r + 10);
        this.blockCtx.clip();
        this.blockCtx.drawImage(tempCanvas, 0, 0);
        this.blockCtx.restore();
        
        // Draw border
        createPuzzlePath(this.blockCtx, 0, r + 10);
        this.blockCtx.strokeStyle = 'rgba(255, 255, 255, 0.5)';
        this.blockCtx.stroke();
        
        // Position the block - align exactly with the cutout
        this.block.style.position = 'absolute';
        this.block.style.left = '0px';
        this.block.style.top = (this.puzzleY - r - 10) + 'px';
    }

    bindEvents() {
        let isMouseDown = false;
        let startX = 0;
        let startY = 0;
        let startLeft = 0;
        
        const handleStart = (e) => {
            if (this.sliderContainer.classList.contains('sc-success')) return;
            if (!this.challengeId) return; // No challenge loaded
            
            e.preventDefault();
            const touch = e.touches?.[0] || e;
            startX = touch.clientX;
            startY = touch.clientY;
            startLeft = parseInt(this.slider.style.left) || 0;
            isMouseDown = true;
            this.startTime = Date.now();
            this.trail = [];
        };
        
        const handleMove = (e) => {
            if (!isMouseDown) return;
            e.preventDefault();
            
            const touch = e.touches?.[0] || e;
            const moveX = touch.clientX - startX;
            const newLeft = Math.max(0, Math.min(startLeft + moveX, this.options.width - 40));
            
            // Move slider
            this.slider.style.left = newLeft + 'px';
            this.sliderMask.style.width = (newLeft + 4) + 'px';
            
            // Move block proportionally
            const maxSliderMove = this.options.width - 40;
            const puzzleRange = this.options.width - this.L;
            const blockLeft = (newLeft / maxSliderMove) * puzzleRange;
            this.block.style.left = blockLeft + 'px';
            
            this.sliderContainer.classList.add('sc-active');
            
            // Record trail
            this.trail.push({
                x: newLeft,
                y: touch.clientY - startY,
                t: Date.now() - this.startTime
            });
        };
        
        const handleEnd = async (e) => {
            if (!isMouseDown) return;
            isMouseDown = false;
            
            this.sliderContainer.classList.remove('sc-active');
            
            // Get current positions
            const sliderLeft = parseFloat(this.slider.style.left) || 0;
            const blockLeft = parseFloat(this.block.style.left) || 0;
            
            // Local validation first
            const sliderDistance = Math.abs(sliderLeft - this.targetSliderX);
            const blockDistance = Math.abs(blockLeft - this.targetX);
            const locallyCorrect = sliderDistance <= this.options.offset || blockDistance <= this.options.offset;
            
            console.log('Local validation:', {
                sliderPos: sliderLeft.toFixed(1),
                targetSliderPos: this.targetSliderX.toFixed(1),
                locallyCorrect: locallyCorrect
            });
            
            if (!locallyCorrect) {
                // Don't even send to server if obviously wrong
                this.handleFailure();
                return;
            }
            
            // Server verification with challenge ID
            try {
                const verificationData = {
                    challengeId: this.challengeId,
                    trail: this.trail
                };
                
                console.log('Sending to secure server:', {
                    challengeId: this.challengeId,
                    trailPoints: this.trail.length
                });
                
                const response = await fetch(this.options.verifyUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify(verificationData)
                });
                
                const result = await response.json();
                console.log('Server response:', result);
                
                if (result.verified) {
                    this.handleSuccess();
                    // Clear challenge ID after successful verification
                    this.challengeId = null;
                } else {
                    this.handleFailure();
                    if (result.error === 'Challenge already used' || 
                        result.error === 'Invalid or expired challenge') {
                        // Need new challenge
                        setTimeout(() => this.reset(), 1000);
                    }
                }
            } catch (error) {
                console.error('Verification error:', error);
                this.handleFailure();
            }
        };
        
        // Bind events
        this.slider.addEventListener('mousedown', handleStart);
        this.slider.addEventListener('touchstart', handleStart, { passive: false });
        document.addEventListener('mousemove', handleMove);
        document.addEventListener('touchmove', handleMove, { passive: false });
        document.addEventListener('mouseup', handleEnd);
        document.addEventListener('touchend', handleEnd);
    }

    handleSuccess() {
        this.sliderContainer.classList.add('sc-success');
        if (this.options.onSuccess) {
            this.options.onSuccess.call(this);
        }
    }

    handleFailure() {
        this.sliderContainer.classList.add('sc-fail');
        if (this.options.onFail) {
            this.options.onFail.call(this);
        }
        setTimeout(() => {
            this.sliderContainer.classList.remove('sc-fail');
            this.reset();
        }, 1000);
    }

    async reset() {
        // Clear UI
        this.sliderContainer.classList.remove('sc-active', 'sc-success', 'sc-fail');
        this.slider.style.left = '0';
        this.block.style.left = '0';
        this.sliderMask.style.width = '0';
        
        this.canvasCtx.clearRect(0, 0, this.canvas.width, this.canvas.height);
        if (this.blockCtx) {
            this.blockCtx.clearRect(0, 0, this.block.width, this.block.height);
        }
        
        this.trail = [];
        this.challengeId = null;
        
        // Request new challenge
        this.sliderText.textContent = this.options.loadingText;
        await this.requestChallenge();
    }

    getRandomNumber(min, max) {
        return Math.round(Math.random() * (max - min) + min);
    }
}

// Export
if (typeof module !== 'undefined' && module.exports) {
    module.exports = SecureSliderCaptcha;
} else if (typeof define === 'function' && define.amd) {
    define([], () => SecureSliderCaptcha);
} else {
    window.SecureSliderCaptcha = SecureSliderCaptcha;
}