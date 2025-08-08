/**
 * Secure Slider Captcha
 * Slider-based interface with server-rendered images
 */

class SecureSliderCaptcha {
    constructor(container, options = {}) {
        this.container = typeof container === 'string' 
            ? document.querySelector(container) 
            : container;
            
        this.options = {
            baseUrl: '/backend/php/secure-captcha.php',
            onSuccess: null,
            onFail: null,
            ...options
        };
        
        this.challengeId = null;
        this.imageWidth = 320;
        this.imageHeight = 200;
        this.pieceSize = 50;
        this.isDragging = false;
        this.currentX = 0;
        
        this.init();
    }
    
    init() {
        this.render();
        this.loadChallenge();
    }
    
    render() {
        this.container.innerHTML = `
            <div class="secure-slider-captcha" style="
                width: 340px;
                background: white;
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 10px;
                font-family: system-ui, -apple-system, sans-serif;
            ">
                <div class="captcha-header" style="
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 10px;
                ">
                    <span style="font-size: 14px; color: #333;">
                        Slide to complete the puzzle
                    </span>
                    <button class="refresh-btn" style="
                        background: none;
                        border: 1px solid #ddd;
                        border-radius: 4px;
                        padding: 4px 8px;
                        cursor: pointer;
                        font-size: 18px;
                    ">↻</button>
                </div>
                
                <div class="captcha-canvas" style="
                    position: relative;
                    width: 320px;
                    height: 200px;
                    background: #f5f5f5;
                    border: 1px solid #ddd;
                    overflow: hidden;
                    margin-bottom: 15px;
                ">
                    <img class="background-img" style="
                        position: absolute;
                        top: 0;
                        left: 0;
                        width: 320px;
                        height: 200px;
                        display: none;
                    ">
                    
                    <img class="puzzle-piece" style="
                        position: absolute;
                        cursor: move;
                        z-index: 10;
                        display: none;
                        user-select: none;
                        -webkit-user-drag: none;
                    ">
                    
                    <div class="loading" style="
                        position: absolute;
                        top: 50%;
                        left: 50%;
                        transform: translate(-50%, -50%);
                        color: #666;
                        font-size: 14px;
                    ">Loading...</div>
                </div>
                
                <div class="slider-container" style="
                    position: relative;
                    height: 40px;
                    background: #f0f0f0;
                    border-radius: 20px;
                    margin-bottom: 10px;
                ">
                    <div class="slider-track" style="
                        position: absolute;
                        top: 50%;
                        left: 20px;
                        right: 20px;
                        height: 4px;
                        background: #ddd;
                        transform: translateY(-50%);
                        border-radius: 2px;
                    "></div>
                    
                    <div class="slider-fill" style="
                        position: absolute;
                        top: 50%;
                        left: 20px;
                        height: 4px;
                        background: #4CAF50;
                        transform: translateY(-50%);
                        border-radius: 2px;
                        width: 0;
                        transition: none;
                    "></div>
                    
                    <div class="slider-handle" style="
                        position: absolute;
                        top: 50%;
                        left: 20px;
                        width: 40px;
                        height: 40px;
                        background: white;
                        border: 2px solid #4CAF50;
                        border-radius: 50%;
                        transform: translateY(-50%);
                        cursor: grab;
                        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        user-select: none;
                    ">
                        <span style="font-size: 20px;">→</span>
                    </div>
                </div>
                
                <div class="captcha-status" style="
                    padding: 8px;
                    background: #f8f9fa;
                    border-radius: 4px;
                    font-size: 13px;
                    text-align: center;
                    color: #666;
                ">
                    Slide to move the puzzle piece
                </div>
            </div>
        `;
        
        // Get elements
        this.canvas = this.container.querySelector('.captcha-canvas');
        this.backgroundImg = this.container.querySelector('.background-img');
        this.puzzlePiece = this.container.querySelector('.puzzle-piece');
        this.loading = this.container.querySelector('.loading');
        this.status = this.container.querySelector('.captcha-status');
        this.refreshBtn = this.container.querySelector('.refresh-btn');
        
        this.sliderContainer = this.container.querySelector('.slider-container');
        this.sliderHandle = this.container.querySelector('.slider-handle');
        this.sliderFill = this.container.querySelector('.slider-fill');
        
        // Calculate slider range
        this.sliderStart = 20;
        this.sliderEnd = 280; // 320 - 40 (handle width)
        this.sliderRange = this.sliderEnd - this.sliderStart;
        
        // Bind events
        this.refreshBtn.addEventListener('click', () => this.reset());
        this.bindSliderEvents();
    }
    
    async loadChallenge() {
        try {
            this.loading.style.display = 'block';
            this.puzzlePiece.style.display = 'none';
            this.backgroundImg.style.display = 'none';
            
            // Get new challenge with slider mode
            const response = await fetch(`${this.options.baseUrl}?action=challenge&mode=slider`);
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.error || 'Failed to load challenge');
            }
            
            this.challengeId = data.challengeId;
            this.imageWidth = data.imageWidth;
            this.imageHeight = data.imageHeight;
            this.pieceSize = data.pieceSize;
            
            // Load background image with challenge ID
            this.backgroundImg.src = `${this.options.baseUrl}?action=background&id=${this.challengeId}&t=${Date.now()}`;
            this.backgroundImg.onload = () => {
                this.backgroundImg.style.display = 'block';
            };
            
            // Load puzzle piece with challenge ID
            this.puzzlePiece.src = `${this.options.baseUrl}?action=piece&id=${this.challengeId}&t=${Date.now()}`;
            this.puzzlePiece.onload = () => {
                this.puzzlePiece.style.display = 'block';
                this.loading.style.display = 'none';
                
                // Set initial position
                // For slider mode, the hole starts at Y=75
                // So the piece top should also be at Y=75
                this.currentX = 0;
                this.puzzlePiece.style.top = '75px'; // Match hole position
                this.updatePiecePosition();
                
                this.status.textContent = 'Slide to move the puzzle piece';
                this.status.style.color = '#666';
            };
            
        } catch (error) {
            this.loading.style.display = 'none';
            this.status.textContent = 'Failed to load captcha';
            this.status.style.color = '#dc3545';
        }
    }
    
    bindSliderEvents() {
        // Prevent default drag
        this.sliderHandle.addEventListener('dragstart', (e) => e.preventDefault());
        
        // Mouse events
        this.sliderHandle.addEventListener('mousedown', (e) => this.startDrag(e));
        document.addEventListener('mousemove', (e) => this.drag(e));
        document.addEventListener('mouseup', (e) => this.endDrag(e));
        
        // Touch events
        this.sliderHandle.addEventListener('touchstart', (e) => this.startDrag(e), {passive: false});
        document.addEventListener('touchmove', (e) => this.drag(e), {passive: false});
        document.addEventListener('touchend', (e) => this.endDrag(e));
    }
    
    startDrag(e) {
        if (e.type === 'mousedown' && e.button !== 0) return;
        
        e.preventDefault();
        this.isDragging = true;
        
        const rect = this.sliderContainer.getBoundingClientRect();
        const clientX = e.type.includes('touch') ? e.touches[0].clientX : e.clientX;
        
        this.dragStartX = clientX - rect.left;
        this.sliderHandle.style.cursor = 'grabbing';
    }
    
    drag(e) {
        if (!this.isDragging) return;
        e.preventDefault();
        
        const rect = this.sliderContainer.getBoundingClientRect();
        const clientX = e.type.includes('touch') ? e.touches[0].clientX : e.clientX;
        
        // Calculate new position
        let newX = clientX - rect.left - 20; // Adjust for handle center
        
        // Constrain to slider bounds
        newX = Math.max(0, Math.min(newX, this.sliderRange));
        
        // Update slider position
        this.sliderHandle.style.left = (this.sliderStart + newX) + 'px';
        this.sliderFill.style.width = newX + 'px';
        
        // Calculate puzzle piece position (map slider position to canvas width)
        const puzzleX = (newX / this.sliderRange) * (this.imageWidth - this.pieceSize - 10);
        this.currentX = puzzleX;
        this.updatePiecePosition();
    }
    
    endDrag(e) {
        if (!this.isDragging) return;
        this.isDragging = false;
        
        this.sliderHandle.style.cursor = 'grab';
        
        // Verify position
        this.verify();
    }
    
    updatePiecePosition() {
        this.puzzlePiece.style.left = `${this.currentX}px`;
    }
    
    async verify() {
        try {
            this.status.textContent = 'Verifying...';
            this.status.style.color = '#666';
            
            // For slider, we use a fixed Y position (middle of canvas)
            const fixedY = 75; // Middle position
            
            const response = await fetch(`${this.options.baseUrl}?action=verify`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    challengeId: this.challengeId,
                    x: this.currentX,
                    y: fixedY
                })
            });
            
            const result = await response.json();
            
            if (result.verified) {
                this.status.textContent = '✅ Success! Captcha solved.';
                this.status.style.color = '#28a745';
                this.sliderHandle.style.pointerEvents = 'none';
                
                if (this.options.onSuccess) {
                    this.options.onSuccess();
                }
            } else {
                const attemptsLeft = result.attempts_left || 0;
                this.status.textContent = `❌ Wrong position. ${attemptsLeft} attempts left.`;
                this.status.style.color = '#dc3545';
                
                if (attemptsLeft === 0) {
                    setTimeout(() => this.reset(), 1500);
                }
                
                if (this.options.onFail) {
                    this.options.onFail();
                }
            }
            
        } catch (error) {
            this.status.textContent = 'Verification failed';
            this.status.style.color = '#dc3545';
        }
    }
    
    reset() {
        this.challengeId = null;
        this.isDragging = false;
        this.sliderHandle.style.pointerEvents = '';
        this.sliderHandle.style.left = this.sliderStart + 'px';
        this.sliderFill.style.width = '0';
        this.currentX = 0;
        this.updatePiecePosition();
        this.loadChallenge();
    }
}

// Export for use
if (typeof module !== 'undefined' && module.exports) {
    module.exports = SecureSliderCaptcha;
}