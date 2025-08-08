/**
 * Working Free Drag Captcha - Based on proven simple HTML version
 * @version 5.0
 */
class FreeDropSliderCaptcha {
    constructor(element, options = {}) {
        this.element = typeof element === 'string' ? 
            document.querySelector(element) : element;
        
        if (!this.element) {
            throw new Error('Element not found');
        }

        this.options = {
            width: 320,
            height: 160,
            pieceSize: 42,
            tabRadius: 9,
            tolerance: 10,
            loadingText: 'Loading...',
            freeText: 'Drag the puzzle piece to the correct position',
            localImages: () => `../frontend/images/Pic${Math.round(Math.random() * 4)}.jpg`,
            challengeUrl: '../backend/php/SliderCaptchaController-secure.php?action=challenge',
            verifyUrl: '../backend/php/SliderCaptchaController-secure.php?action=verify',
            onSuccess: null,
            onFail: null,
            onRefresh: null,
            ...options
        };

        this.challengeId = null;
        this.targetX = 0;
        this.targetY = 0;
        this.currentX = 0;
        this.currentY = 0;
        this.isDragging = false;
        this.offsetX = 0;
        this.offsetY = 0;
        this.trail = [];
        this.startTime = 0;
        
        this.init();
    }

    async init() {
        this.setupContainer();
        await this.requestChallenge();
    }

    setupContainer() {
        // Clear and setup container
        this.element.innerHTML = `
            <div class="captcha-wrapper" style="width: ${this.options.width}px; margin: 0 auto;">
                <div class="captcha-container" style="position: relative; width: ${this.options.width}px; height: ${this.options.height}px; background: #fff; border: 2px solid #ddd; overflow: hidden;">
                    <canvas id="bg-canvas-${Date.now()}" width="${this.options.width}" height="${this.options.height}" style="position: absolute; left: 0; top: 0; z-index: 1;"></canvas>
                    <canvas id="puzzle-piece-${Date.now()}" width="60" height="70" style="position: absolute; cursor: move; z-index: 10;"></canvas>
                    <button class="refresh-btn" style="position: absolute; top: 5px; right: 5px; z-index: 20; background: rgba(0,0,0,0.5); color: white; border: none; padding: 5px 10px; cursor: pointer; border-radius: 3px;">↻</button>
                </div>
                <div class="info-bar" style="text-align: center; padding: 10px; background: #333; color: white;">
                    <span class="info-text">${this.options.loadingText}</span>
                </div>
            </div>
        `;
        
        // Get elements
        this.container = this.element.querySelector('.captcha-container');
        this.bgCanvas = this.container.querySelector('canvas:first-child');
        this.puzzlePiece = this.container.querySelector('canvas:last-of-type');
        this.refreshBtn = this.container.querySelector('.refresh-btn');
        this.infoText = this.element.querySelector('.info-text');
        
        this.bgCtx = this.bgCanvas.getContext('2d');
        this.pieceCtx = this.puzzlePiece.getContext('2d');
        
        // Bind refresh
        this.refreshBtn.addEventListener('click', () => {
            this.reset();
            if (this.options.onRefresh) {
                this.options.onRefresh();
            }
        });
    }

    async requestChallenge() {
        try {
            console.log('Requesting challenge...');
            
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
            
            // Random Y position
            this.targetY = Math.floor(Math.random() * (this.options.height - this.options.pieceSize - 20)) + 10;
            
            // Random start position
            this.currentX = Math.floor(Math.random() * 100);
            this.currentY = Math.floor(Math.random() * (this.options.height - 60));
            
            console.log('Challenge:', {
                id: this.challengeId,
                target: { x: this.targetX, y: this.targetY },
                start: { x: this.currentX, y: this.currentY }
            });
            
            this.loadImage();
            
        } catch (error) {
            console.error('Challenge error:', error);
            this.infoText.textContent = 'Failed to load captcha';
        }
    }

    loadImage() {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        
        img.onload = () => {
            console.log('Image loaded');
            this.drawBackground(img);
            this.drawPuzzlePiece(img);
            this.positionPiece(this.currentX, this.currentY);
            this.setupDragEvents();
            this.infoText.textContent = this.options.freeText;
        };
        
        img.onerror = () => {
            console.log('Image error, trying local');
            img.src = this.options.localImages();
        };
        
        img.src = this.options.localImages();
        this.img = img;
    }

    drawBackground(img) {
        // Clear canvas
        this.bgCtx.clearRect(0, 0, this.options.width, this.options.height);
        
        // Draw image
        this.bgCtx.drawImage(img, 0, 0, this.options.width, this.options.height);
        
        // Cut out puzzle shape
        this.bgCtx.save();
        this.bgCtx.globalCompositeOperation = 'destination-out';
        this.drawPuzzleShape(this.bgCtx, this.targetX, this.targetY);
        this.bgCtx.fill();
        this.bgCtx.restore();
        
        // Draw outline
        this.bgCtx.strokeStyle = 'rgba(255,255,255,0.8)';
        this.bgCtx.lineWidth = 2;
        this.drawPuzzleShape(this.bgCtx, this.targetX, this.targetY);
        this.bgCtx.stroke();
    }

    drawPuzzlePiece(img) {
        const size = 60;
        const extraHeight = 10;
        
        // Clear
        this.pieceCtx.clearRect(0, 0, size, size + extraHeight);
        
        // Create temp canvas to extract piece
        const tempCanvas = document.createElement('canvas');
        tempCanvas.width = size;
        tempCanvas.height = size + extraHeight;
        const tempCtx = tempCanvas.getContext('2d');
        
        // Draw portion of image
        tempCtx.drawImage(
            img,
            this.targetX,
            this.targetY - 10,
            size,
            size + extraHeight,
            0,
            0,
            size,
            size + extraHeight
        );
        
        // Apply clipping and draw
        this.pieceCtx.save();
        this.drawPuzzleShape(this.pieceCtx, 0, 10);
        this.pieceCtx.clip();
        this.pieceCtx.drawImage(tempCanvas, 0, 0);
        this.pieceCtx.restore();
        
        // Draw border
        this.pieceCtx.strokeStyle = 'rgba(255,255,255,0.8)';
        this.pieceCtx.lineWidth = 2;
        this.drawPuzzleShape(this.pieceCtx, 0, 10);
        this.pieceCtx.stroke();
    }

    drawPuzzleShape(ctx, x, y) {
        const l = this.options.pieceSize;
        const r = this.options.tabRadius;
        const PI = Math.PI;
        
        ctx.beginPath();
        ctx.moveTo(x, y);
        ctx.arc(x + l/2, y - r + 2, r, 0.72 * PI, 2.26 * PI);
        ctx.lineTo(x + l, y);
        ctx.arc(x + l + r - 2, y + l/2, r, 1.21 * PI, 2.78 * PI);
        ctx.lineTo(x + l, y + l);
        ctx.lineTo(x, y + l);
        ctx.arc(x + r - 2, y + l/2, r + 0.4, 2.76 * PI, 1.24 * PI, true);
        ctx.lineTo(x, y);
        ctx.closePath();
    }

    positionPiece(x, y) {
        this.puzzlePiece.style.left = x + 'px';
        this.puzzlePiece.style.top = y + 'px';
        
        // No visual feedback while dragging - removed green border
        this.puzzlePiece.style.border = '2px solid transparent';
        this.puzzlePiece.style.boxShadow = 'none';
    }

    setupDragEvents() {
        console.log('Setting up drag events');
        
        // Remove any existing listeners
        this.puzzlePiece.onmousedown = null;
        this.puzzlePiece.ontouchstart = null;
        
        // Mouse events
        this.puzzlePiece.onmousedown = (e) => this.startDrag(e);
        document.onmousemove = (e) => this.drag(e);
        document.onmouseup = (e) => this.endDrag(e);
        
        // Touch events
        this.puzzlePiece.ontouchstart = (e) => this.startDrag(e);
        document.ontouchmove = (e) => this.drag(e);
        document.ontouchend = (e) => this.endDrag(e);
    }

    startDrag(e) {
        e.preventDefault();
        this.isDragging = true;
        this.startTime = Date.now();
        this.trail = [];
        
        const clientX = e.type.includes('touch') ? e.touches[0].clientX : e.clientX;
        const clientY = e.type.includes('touch') ? e.touches[0].clientY : e.clientY;
        
        const rect = this.puzzlePiece.getBoundingClientRect();
        this.offsetX = clientX - rect.left;
        this.offsetY = clientY - rect.top;
        
        this.puzzlePiece.style.cursor = 'grabbing';
        console.log('Drag started');
    }

    drag(e) {
        if (!this.isDragging) return;
        e.preventDefault();
        
        const clientX = e.type.includes('touch') ? e.touches[0].clientX : e.clientX;
        const clientY = e.type.includes('touch') ? e.touches[0].clientY : e.clientY;
        
        const containerRect = this.container.getBoundingClientRect();
        
        // Calculate new position
        let newX = clientX - containerRect.left - this.offsetX;
        let newY = clientY - containerRect.top - this.offsetY;
        
        // Constrain to bounds
        newX = Math.max(0, Math.min(newX, this.options.width - 60));
        newY = Math.max(0, Math.min(newY, this.options.height - 70));
        
        this.currentX = newX;
        this.currentY = newY;
        
        this.positionPiece(newX, newY);
        
        // Record trail
        this.trail.push({
            x: newX,
            y: newY,
            t: Date.now() - this.startTime
        });
    }

    endDrag(e) {
        if (!this.isDragging) return;
        this.isDragging = false;
        
        this.puzzlePiece.style.cursor = 'move';
        console.log('Drag ended at:', this.currentX, this.currentY);
        
        // Check if close to target
        // The correct position for the puzzle piece canvas is targetY - 10
        const correctY = this.targetY - 10;
        const distance = Math.sqrt(
            Math.pow(this.currentX - this.targetX, 2) + 
            Math.pow(this.currentY - correctY, 2)
        );
        
        if (distance <= this.options.tolerance) {
            // Don't snap - just verify at current position to avoid jump
            // The user has already positioned it close enough
            
            this.verify();
        }
    }

    async verify() {
        try {
            const data = {
                challengeId: this.challengeId,
                trail: this.trail,
                mode: 'free'
            };
            
            console.log('Verifying...', data);
            
            const response = await fetch(this.options.verifyUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(data)
            });
            
            const result = await response.json();
            console.log('Result:', result);
            
            if (result.verified) {
                this.infoText.textContent = '✅ Success!';
                this.infoText.style.color = '#4CAF50';
                if (this.options.onSuccess) {
                    this.options.onSuccess();
                }
            } else {
                this.infoText.textContent = '❌ Failed';
                this.infoText.style.color = '#f44336';
                if (this.options.onFail) {
                    this.options.onFail();
                }
                setTimeout(() => this.reset(), 1500);
            }
        } catch (error) {
            console.error('Verify error:', error);
        }
    }

    async reset() {
        this.isDragging = false;
        this.trail = [];
        this.challengeId = null;
        
        this.infoText.textContent = this.options.loadingText;
        this.infoText.style.color = '';
        
        this.bgCtx.clearRect(0, 0, this.options.width, this.options.height);
        this.pieceCtx.clearRect(0, 0, 60, 70);
        
        await this.requestChallenge();
    }
}

// Export
window.FreeDropSliderCaptcha = FreeDropSliderCaptcha;