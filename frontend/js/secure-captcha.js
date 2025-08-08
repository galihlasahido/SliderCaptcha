/**
 * Secure Captcha Frontend
 * Works with server-rendered images where position is never exposed
 */

class SecureCaptcha {
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
        this.currentY = 0;
        
        this.init();
    }
    
    init() {
        this.render();
        this.loadChallenge();
    }
    
    render() {
        this.container.innerHTML = `
            <div class="secure-captcha" style="
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
                        Drag the puzzle piece to fit
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
                
                <div class="captcha-status" style="
                    margin-top: 10px;
                    padding: 8px;
                    background: #f8f9fa;
                    border-radius: 4px;
                    font-size: 13px;
                    text-align: center;
                    color: #666;
                ">
                    Waiting for verification...
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
        
        // Bind events
        this.refreshBtn.addEventListener('click', () => this.reset());
        this.bindDragEvents();
    }
    
    async loadChallenge() {
        try {
            this.loading.style.display = 'block';
            this.puzzlePiece.style.display = 'none';
            this.backgroundImg.style.display = 'none';
            
            // Get new challenge with freedrag mode
            const response = await fetch(`${this.options.baseUrl}?action=challenge&mode=freedrag`);
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
                
                // Set initial random position
                this.currentX = Math.random() * (this.imageWidth - this.pieceSize - 10);
                this.currentY = Math.random() * (this.imageHeight - this.pieceSize);
                this.updatePiecePosition();
                
                this.status.textContent = 'Drag the piece to the correct position';
                this.status.style.color = '#666';
            };
            
        } catch (error) {
            this.loading.style.display = 'none';
            this.status.textContent = 'Failed to load captcha';
            this.status.style.color = '#dc3545';
        }
    }
    
    bindDragEvents() {
        // Prevent default drag
        this.puzzlePiece.addEventListener('dragstart', (e) => e.preventDefault());
        
        // Mouse events
        this.puzzlePiece.addEventListener('mousedown', (e) => this.startDrag(e));
        document.addEventListener('mousemove', (e) => this.drag(e));
        document.addEventListener('mouseup', (e) => this.endDrag(e));
        
        // Touch events
        this.puzzlePiece.addEventListener('touchstart', (e) => this.startDrag(e), {passive: false});
        document.addEventListener('touchmove', (e) => this.drag(e), {passive: false});
        document.addEventListener('touchend', (e) => this.endDrag(e));
    }
    
    startDrag(e) {
        if (e.type === 'mousedown' && e.button !== 0) return;
        
        e.preventDefault();
        this.isDragging = true;
        
        const rect = this.puzzlePiece.getBoundingClientRect();
        const canvasRect = this.canvas.getBoundingClientRect();
        
        const clientX = e.type.includes('touch') ? e.touches[0].clientX : e.clientX;
        const clientY = e.type.includes('touch') ? e.touches[0].clientY : e.clientY;
        
        this.dragOffsetX = clientX - rect.left;
        this.dragOffsetY = clientY - rect.top;
        
        this.puzzlePiece.style.cursor = 'grabbing';
    }
    
    drag(e) {
        if (!this.isDragging) return;
        e.preventDefault();
        
        const canvasRect = this.canvas.getBoundingClientRect();
        const clientX = e.type.includes('touch') ? e.touches[0].clientX : e.clientX;
        const clientY = e.type.includes('touch') ? e.touches[0].clientY : e.clientY;
        
        // Calculate new position
        let newX = clientX - canvasRect.left - this.dragOffsetX;
        let newY = clientY - canvasRect.top - this.dragOffsetY;
        
        // Constrain to canvas bounds
        newX = Math.max(0, Math.min(newX, this.imageWidth - this.pieceSize - 10));
        newY = Math.max(0, Math.min(newY, this.imageHeight - this.pieceSize));
        
        this.currentX = newX;
        this.currentY = newY;
        this.updatePiecePosition();
    }
    
    endDrag(e) {
        if (!this.isDragging) return;
        this.isDragging = false;
        
        this.puzzlePiece.style.cursor = 'move';
        
        // Verify position
        this.verify();
    }
    
    updatePiecePosition() {
        this.puzzlePiece.style.left = `${this.currentX}px`;
        this.puzzlePiece.style.top = `${this.currentY}px`;
    }
    
    async verify() {
        try {
            this.status.textContent = 'Verifying...';
            this.status.style.color = '#666';
            
            const response = await fetch(`${this.options.baseUrl}?action=verify`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    challengeId: this.challengeId,
                    x: this.currentX,
                    y: this.currentY
                })
            });
            
            const result = await response.json();
            
            if (result.verified) {
                this.status.textContent = '✅ Success! Captcha solved.';
                this.status.style.color = '#28a745';
                this.puzzlePiece.style.pointerEvents = 'none';
                // No visual effects on the puzzle piece
                
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
        this.puzzlePiece.style.pointerEvents = '';
        this.loadChallenge();
    }
}

// Export for use
if (typeof module !== 'undefined' && module.exports) {
    module.exports = SecureCaptcha;
}