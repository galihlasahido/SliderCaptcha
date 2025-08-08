/**
 * Server-Side Rendered Slider Captcha Frontend
 * Works with server-generated puzzle images where position is never exposed
 */

class SecureSliderCaptcha {
    constructor(container, options = {}) {
        this.container = typeof container === 'string' 
            ? document.querySelector(container) 
            : container;
            
        this.options = {
            width: 320,
            height: 200,
            challengeUrl: '/backend/php/SliderCaptchaController-serverside.php?action=challenge',
            verifyUrl: '/backend/php/SliderCaptchaController-serverside.php?action=verify',
            onSuccess: null,
            onFail: null,
            ...options
        };
        
        this.challengeId = null;
        this.isDragging = false;
        this.startX = 0;
        this.startY = 0;
        this.currentX = 0;
        this.currentY = 0;
        this.trail = [];
        
        this.init();
    }
    
    init() {
        // Create DOM structure
        this.container.innerHTML = `
            <div class="captcha-wrapper" style="position: relative; width: ${this.options.width}px;">
                <div class="captcha-header">
                    <span class="captcha-title">Drag puzzle piece to correct position</span>
                    <button class="refresh-btn" style="float: right;">↻</button>
                </div>
                <div class="captcha-body" style="position: relative; height: ${this.options.height}px; overflow: hidden; background: #f0f0f0;">
                    <img class="background-img" style="position: absolute; top: 0; left: 0;">
                    <img class="puzzle-piece" style="position: absolute; cursor: move; z-index: 10;">
                    <div class="loading" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);">Loading...</div>
                </div>
                <div class="captcha-info" style="text-align: center; padding: 10px; font-size: 14px;">
                    Drag the puzzle piece to the matching position
                </div>
            </div>
        `;
        
        // Get elements
        this.wrapper = this.container.querySelector('.captcha-wrapper');
        this.body = this.container.querySelector('.captcha-body');
        this.backgroundImg = this.container.querySelector('.background-img');
        this.puzzlePiece = this.container.querySelector('.puzzle-piece');
        this.loading = this.container.querySelector('.loading');
        this.info = this.container.querySelector('.captcha-info');
        this.refreshBtn = this.container.querySelector('.refresh-btn');
        
        // Bind events
        this.refreshBtn.addEventListener('click', () => this.reset());
        this.bindDragEvents();
        
        // Load challenge
        this.loadChallenge();
    }
    
    async loadChallenge() {
        try {
            this.loading.style.display = 'block';
            this.puzzlePiece.style.display = 'none';
            this.backgroundImg.style.display = 'none';
            
            // Get challenge from server
            const response = await fetch(this.options.challengeUrl);
            if (!response.ok) throw new Error('Failed to load challenge');
            
            const data = await response.json();
            
            if (data.error) {
                throw new Error(data.error);
            }
            
            this.challengeId = data.challengeId;
            
            // Load background image with puzzle hole
            this.backgroundImg.src = data.backgroundUrl;
            this.backgroundImg.onload = () => {
                this.backgroundImg.style.display = 'block';
            };
            
            // Load puzzle piece
            this.puzzlePiece.src = data.pieceUrl;
            this.puzzlePiece.onload = () => {
                this.puzzlePiece.style.display = 'block';
                this.loading.style.display = 'none';
                
                // Set initial random position for piece
                this.currentX = Math.random() * (this.options.width - data.pieceSize);
                this.currentY = Math.random() * (this.options.height - data.pieceSize);
                this.updatePiecePosition();
            };
            
            // Store piece size for bounds checking
            this.pieceSize = data.pieceSize;
            
        } catch (error) {
            this.loading.style.display = 'none';
            this.info.textContent = 'Failed to load captcha. Please refresh.';
            this.info.style.color = 'red';
        }
    }
    
    bindDragEvents() {
        // Mouse events
        this.puzzlePiece.addEventListener('mousedown', (e) => this.startDrag(e));
        document.addEventListener('mousemove', (e) => this.drag(e));
        document.addEventListener('mouseup', (e) => this.endDrag(e));
        
        // Touch events
        this.puzzlePiece.addEventListener('touchstart', (e) => this.startDrag(e));
        document.addEventListener('touchmove', (e) => this.drag(e));
        document.addEventListener('touchend', (e) => this.endDrag(e));
    }
    
    startDrag(e) {
        e.preventDefault();
        this.isDragging = true;
        this.startTime = Date.now();
        this.trail = [];
        
        const rect = this.body.getBoundingClientRect();
        const clientX = e.type.includes('touch') ? e.touches[0].clientX : e.clientX;
        const clientY = e.type.includes('touch') ? e.touches[0].clientY : e.clientY;
        
        this.startX = clientX - rect.left - this.currentX;
        this.startY = clientY - rect.top - this.currentY;
        
        this.puzzlePiece.style.cursor = 'grabbing';
    }
    
    drag(e) {
        if (!this.isDragging) return;
        e.preventDefault();
        
        const rect = this.body.getBoundingClientRect();
        const clientX = e.type.includes('touch') ? e.touches[0].clientX : e.clientX;
        const clientY = e.type.includes('touch') ? e.touches[0].clientY : e.clientY;
        
        // Calculate new position
        let newX = clientX - rect.left - this.startX;
        let newY = clientY - rect.top - this.startY;
        
        // Constrain to bounds
        newX = Math.max(0, Math.min(newX, this.options.width - this.pieceSize));
        newY = Math.max(0, Math.min(newY, this.options.height - this.pieceSize));
        
        this.currentX = newX;
        this.currentY = newY;
        
        // Record movement trail
        this.trail.push({
            x: newX,
            y: newY,
            t: Date.now() - this.startTime
        });
        
        this.updatePiecePosition();
    }
    
    endDrag(e) {
        if (!this.isDragging) return;
        this.isDragging = false;
        
        this.puzzlePiece.style.cursor = 'move';
        
        // Verify position with server
        this.verify();
    }
    
    updatePiecePosition() {
        this.puzzlePiece.style.left = this.currentX + 'px';
        this.puzzlePiece.style.top = this.currentY + 'px';
    }
    
    async verify() {
        try {
            const verifyData = {
                challengeId: this.challengeId,
                x: this.currentX,
                y: this.currentY,
                trail: this.trail
            };
            
            const response = await fetch(this.options.verifyUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(verifyData)
            });
            
            const result = await response.json();
            
            if (result.verified) {
                this.info.textContent = '✅ Success! Captcha verified.';
                this.info.style.color = 'green';
                this.puzzlePiece.style.pointerEvents = 'none';
                
                if (this.options.onSuccess) {
                    this.options.onSuccess();
                }
            } else {
                this.info.textContent = `❌ Incorrect position. ${result.attempts_left} attempts remaining.`;
                this.info.style.color = 'red';
                
                if (result.attempts_left === 0) {
                    setTimeout(() => this.reset(), 2000);
                }
                
                if (this.options.onFail) {
                    this.options.onFail();
                }
            }
            
        } catch (error) {
            this.info.textContent = 'Verification failed. Please try again.';
            this.info.style.color = 'red';
        }
    }
    
    reset() {
        this.challengeId = null;
        this.isDragging = false;
        this.trail = [];
        this.info.textContent = 'Drag the puzzle piece to the matching position';
        this.info.style.color = '';
        this.puzzlePiece.style.pointerEvents = '';
        this.loadChallenge();
    }
}

// Export for use
if (typeof module !== 'undefined' && module.exports) {
    module.exports = SecureSliderCaptcha;
}