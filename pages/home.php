<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>OneLink x WISP | High-Speed Fiber Internet in sirari</title>
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta content="WISP fiber, fiber internet mombasa, home fiber siari, business internet siari, OneLink fiber, wireless internet" name="keywords">
    <meta content="OneLink Data Fiber Technologies powered by WISP. Reliable and affordable pure optical fiber internet across Mombasa County. Plans from KES 1,500/month." name="description">
    <meta property="og:title" content="OneLink x WISP | Fast & Reliable Fiber Internet in Mombasa">
    <meta property="og:description" content="Pure optical fiber intesh 30,000. CAK licensed & EHS certified.">
    <meta property="og:type" content="website">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">

    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #ffffff;
            scroll-behavior: smooth;
        }

        :root {
            --luminous-blue: #0ea5e9;
            --luminous-cyan: #06b6d4;
            --luminous-green: #10b981;
            --luminous-purple: #8b5cf6;
            --luminous-orange: #f97316;
            --luminous-pink: #ec489a;
            --luminous-teal: #14b8a6;
            --luminous-yellow: #eab308;
            --luminous-red: #ef4444;
            --navy-blue: #0a192f;
        }

        .text-luminous-blue { color: var(--luminous-blue); }
        .text-luminous-cyan { color: var(--luminous-cyan); }
        .text-luminous-green { color: var(--luminous-green); }
        .text-luminous-purple { color: var(--luminous-purple); }
        .text-luminous-orange { color: var(--luminous-orange); }
        .text-luminous-pink { color: var(--luminous-pink); }
        .text-luminous-teal { color: var(--luminous-teal); }
        .text-luminous-yellow { color: var(--luminous-yellow); }
        .text-navy-blue { color: var(--navy-blue); }

        .btn-luminous {
            background: linear-gradient(135deg, var(--luminous-blue), var(--luminous-cyan));
            border: none;
            color: white;
            font-weight: 600;
            padding: 12px 28px;
            border-radius: 40px;
            transition: all 0.3s ease;
        }

        .btn-luminous:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(14, 165, 233, 0.3);
            color: white;
        }

        .btn-outline-luminous {
            border: 2px solid var(--luminous-blue);
            background: transparent;
            color: var(--luminous-blue);
            font-weight: 600;
            border-radius: 40px;
            transition: 0.3s;
        }

        .btn-outline-luminous:hover {
            background: var(--luminous-blue);
            color: white;
        }

        .glass-card {
            background: white;
            border-radius: 28px;
            transition: all 0.3s;
            border: 1px solid rgba(0, 0, 0, 0.06);
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.03);
        }

        .glass-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 35px -12px rgba(0, 0, 0, 0.1);
        }

        .pricing-table-modern {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: white;
            border-radius: 28px;
            overflow: hidden;
            border: 1px solid #f0f2f5;
        }

        .pricing-table-modern th {
            background: #f8fafc;
            color: #1e293b;
            padding: 16px 20px;
            font-weight: 700;
            font-size: 0.9rem;
            border-bottom: 2px solid #eef2f6;
        }

        .pricing-table-modern td {
            padding: 14px 20px;
            border-bottom: 1px solid #f0f2f5;
            vertical-align: middle;
        }

        .pricing-table-modern tr:last-child td {
            border-bottom: none;
        }

        .pricing-table-modern tr:hover td {
            background: #fafcff;
        }

        .highlight-max-yellow {
            background-color: #fef9e3 !important;
        }

        .highlight-max-yellow td {
            background-color: #fef9e3;
        }

        .badge-luminous {
            background: linear-gradient(135deg, var(--luminous-orange), #fdba74);
            color: #1e1e2a;
            padding: 4px 12px;
            border-radius: 40px;
            font-size: 0.7rem;
            font-weight: 700;
            display: inline-block;
        }

        .badge-popular {
            background: linear-gradient(135deg, var(--luminous-green), #34d399);
            color: white;
            padding: 4px 12px;
            border-radius: 40px;
            font-size: 0.7rem;
            font-weight: 700;
            display: inline-block;
        }

        .badge-max {
            background: linear-gradient(135deg, var(--luminous-yellow), #fbbf24);
            color: #1e1e2a;
            padding: 4px 12px;
            border-radius: 40px;
            font-size: 0.7rem;
            font-weight: 700;
            display: inline-block;
        }

        .floating-buttons {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            gap: 15px;
            align-items: flex-end;
        }

        .whatsapp-float {
            animation: pulse 2s infinite;
        }

        .whatsapp-float a {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 60px;
            height: 60px;
            background: #25d366;
            border-radius: 50%;
            box-shadow: 0 8px 25px rgba(37, 211, 102, 0.3);
            transition: all 0.3s;
            text-decoration: none;
        }

        .whatsapp-float a:hover {
            transform: scale(1.08);
        }

        .whatsapp-float i {
            font-size: 32px;
            color: white;
        }

        .back-to-top {
            width: 50px;
            height: 50px;
            background: var(--luminous-purple);
            border-radius: 50%;
            display: none;
            align-items: center;
            justify-content: center;
            color: white;
            transition: 0.3s;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }

        .back-to-top:hover {
            background: var(--luminous-pink);
            transform: translateY(-3px);
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .feature-icon-modern {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #f8fafd, #ffffff);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.04);
            transition: all 0.3s ease;
        }

        .feature-icon-modern:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 25px rgba(0, 0, 0, 0.08);
        }

        .why-choose-card {
            background: white;
            border-radius: 24px;
            padding: 1.5rem 1rem;
            text-align: center;
            transition: all 0.3s;
            border: 1px solid rgba(0, 0, 0, 0.05);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02);
        }

        .why-choose-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 30px -12px rgba(0, 0, 0, 0.1);
            border-color: rgba(14, 165, 233, 0.2);
        }

        .navbar-custom {
            background: #ffffff !important;
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.05);
        }

        .navbar-custom .navbar-nav .nav-link {
            color: #1e293b;
            font-weight: 500;
        }

        .navbar-custom .navbar-nav .nav-link:hover {
            color: var(--luminous-blue);
        }

        .logo-img {
            height: 50px;
            width: auto;
            display: block;
        }

        .hero-section {
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
        }

        .testimonial-card {
            background: white;
            border-radius: 24px;
            transition: all 0.3s;
            border: 1px solid #f0f2f5;
        }

        .testimonial-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 15px 30px -12px rgba(0, 0, 0, 0.08);
        }

        .coverage-card {
            background: linear-gradient(135deg, #f8fafc, #ffffff);
            border-radius: 28px;
            padding: 2rem;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .minimal-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8rem;
            font-weight: 500;
            background: #ffffff;
            padding: 6px 14px;
            border-radius: 40px;
            border: 1px solid #eef2f6;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
        }

        .hero-tagline {
            font-size: 0.85rem;
            letter-spacing: 0.3px;
            margin-bottom: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        @media (max-width: 768px) {
            .logo-img { height: 40px; }
            .hero-section { text-align: center; }
            .hero-tagline { justify-content: center; }
        }

        #spinner {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }

        .spinner-border-custom {
            width: 50px;
            height: 50px;
            border: 4px solid #e2e8f0;
            border-top: 4px solid var(--luminous-blue);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>

<body>
    <div id="spinner">
        <div class="spinner-border-custom"></div>
    </div>

    <div class="container-fluid bg-light text-dark py-2 px-4 d-none d-lg-flex border-bottom">
        <div class="container d-flex justify-content-between">
            <div class="d-flex align-items-center gap-4">
                <div><i class="fa fa-map-marker-alt text-luminous-blue me-2"></i>sokoni, sirari</div>
                <div><i class="fa fa-phone-alt text-luminous-blue me-2"></i>0768 974 189</div>
                <div><i class="fa fa-envelope text-luminous-blue me-2"></i>nyaragita12@gmail.com</div>
            </div>
            <div class="d-flex gap-3">
                <a href="#" class="text-secondary"><i class="fab fa-facebook-f"></i></a>
                <a href="#" class="text-secondary"><i class="fab fa-twitter"></i></a>
                <a href="#" class="text-secondary"><i class="fab fa-instagram"></i></a>
            </div>
        </div>
    </div>

    <nav class="navbar navbar-expand-lg navbar-light navbar-custom sticky-top py-3 shadow-sm">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="#">
                <img src="" alt="" class="logo-img">
                <span class="fw-bold" style="font-family: 'Space Grotesk';">OneLink <span class="text-luminous-blue">Fiber</span></span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link active" href="#">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="#packages">WISP Bundles</a></li>
                    <li class="nav-item"><a class="nav-link" href="#pricing-table">Plans</a></li>
                    <li class="nav-item"><a class="nav-link" href="#about">About</a></li>
                    <li class="nav-item"><a class="nav-link" href="/login">Login</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid hero-section py-5">
        <div class="container py-5">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <div class="hero-tagline text-luminous-blue text-uppercase fw-semibold">
                        <i class="fas fa-wifi"></i>
                        <span>Optimized for WISP Networks</span>
                    </div>
                    <h1 class="display-3 fw-bold mb-3 mt-2">Unlimited Fiber.<br><span style="color: var(--luminous-blue);">Powered by WISP.</span></h1>
                    <p class="lead text-secondary mb-4">Get the best out of your WISP connection with our pure optical fiber. Stable, fast, and local support in sirari.</p>
                    <div class="d-flex gap-3 mb-4 flex-wrap">
                        <a href="/login?tab=register" class="btn btn-luminous">Register</a>
                        <a href="/login" class="btn btn-outline-luminous">LOGIN</a>
                        <a href="#packages" class="btn btn-outline-luminous">WISP Bundle info</a>
                    </div>
                    <div class="d-flex flex-wrap gap-3">
                        <div class="minimal-badge"><i class="fas fa-check-circle text-luminous-green me-1"></i><span>Plug & Play</span></div>
                        <div class="minimal-badge"><i class="fas fa-infinity text-luminous-orange me-1"></i><span>No Data Caps</span></div>
                        <div class="minimal-badge"><i class="fas fa-microchip text-luminous-blue me-1"></i><span>WISP Powered</span></div>
                    </div>
                </div>
                <div class="col-lg-6 mt-5 mt-lg-0">
                    <img src="https://images.unsplash.com/photo-1544197150-b99a580bb7a8?w=600&h=450&fit=crop" class="img-fluid rounded-4 shadow" alt="WISP Fiber Connection">
                </div>
            </div>
        </div>
    </div>

    <div class="container py-5" id="about">
        <div class="row align-items-stretch g-5">
            <div class="col-lg-6">
                <h5 class="fw-bold text-luminous-blue text-uppercase">Seamless Setup</h5>
                <h2 class="fw-bold mb-3">WISP Fiber Experience</h2>
                <p class="text-secondary">At OneLink Data Fiber Technologies, we ensure your WISP connection is perfectly configured for our high-speed network. We serve Bamburi and Greater Mombasa with pure fiber that handles 4K streaming and gaming with ease.</p>
                <div class="row mt-4">
                    <div class="col-6 mb-3"><i class="fa fa-router text-luminous-blue me-2"></i> Pre-configured WISP</div>
                    <div class="col-6 mb-3"><i class="fa fa-shield-alt text-luminous-cyan me-2"></i> Secure WiFi</div>
                    <div class="col-6 mb-3"><i class="fa fa-bolt text-luminous-green me-2"></i> Instant Activation</div>
                    <div class="col-6 mb-3"><i class="fa fa-tools text-luminous-purple me-2"></i> Expert On-site Setup</div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="coverage-card h-100 d-flex flex-column justify-content-center">
                    <h3 class="fw-bold mb-3">Check Your Location</h3>
                    <p class="text-secondary mb-4">Confirm if your area is ready for a WISP + Fiber installation.</p>
                    <div class="input-group">
                        <input type="text" class="form-control form-control-lg border-luminous-blue" placeholder="e.g. sirari, N...">
                        <button class="btn btn-luminous px-4">Check Now</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="container py-5" id="packages">
        <div class="text-center mb-5">
            <h5 class="text-luminous-blue text-uppercase fw-bold">Our Top Bundles</h5>
            <h2 class="fw-bold">Fiber Plans Perfect for WISP</h2>
            <p class="text-secondary">Reliable hardware meets lightning-fast speeds</p>
        </div>
        <div class="row g-4">
            <div class="col-md-4"><div class="glass-card p-4 h-100 text-center"><div class="feature-icon-modern mx-auto"><i class="fa fa-home fs-1 text-luminous-blue"></i></div><h4 class="fw-bold mt-3">Basic WISP Home</h4><p class="text-muted">Best for small apartments</p><div class="bg-light rounded-3 p-2 mb-3"><span class="fw-bold fs-4">Tsh 5,000</span><span class="text-muted">/month</span></div><p class="small">5 Mbps Unlimited | WISP Compatible</p><button class="btn btn-outline-luminous w-100 mt-2">Order Bundle</button></div></div>
            <div class="col-md-4"><div class="glass-card p-4 h-100 text-center position-relative overflow-hidden"><span class="badge-luminous position-absolute top-0 end-0 m-3">Popular</span><div class="feature-icon-modern mx-auto"><i class="fa fa-wifi fs-1 text-luminous-green"></i></div><h4 class="fw-bold mt-3">Family Streaming</h4><p class="text-muted">WISP Optimized for HD</p><div class="bg-light rounded-3 p-2 mb-3"><span class="fw-bold fs-4">Tsh 15,000</span><span class="text-muted">/month</span></div><p class="small">10 Mbps Unlimited | High Stability</p><button class="btn btn-luminous w-100 mt-2">Order Bundle</button></div></div>
            <div class="col-md-4"><div class="glass-card p-4 h-100 text-center"><div class="feature-icon-modern mx-auto"><i class="fa fa-briefcase fs-1 text-luminous-purple"></i></div><h4 class="fw-bold mt-3">WISP Pro Business</h4><p class="text-muted">For offices and cyber cafes</p><div class="bg-light rounded-3 p-2 mb-3"><span class="fw-bold fs-4">Tsh 30,000</span><span class="text-muted">/month</span></div><p class="small">30 Mbps | Multi-user support</p><button class="btn btn-outline-luminous w-100 mt-2">Order Bundle</button></div></div>
        </div>
    </div>

    <div class="container py-5" id="pricing-table">
        <div class="text-center mb-5">
            <h5 class="text-luminous-cyan text-uppercase fw-bold">Full Plan Menu</h5>
            <h2 class="fw-bold">Available Speed Tiers</h2>
        </div>
        <div class="table-responsive">
            <table class="pricing-table-modern">
                <thead>
                    <tr><th>Speed</th><th>Recommended Router</th><th>Monthly Price</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>5 Mbps</strong></td><td>WISP</td><td class="fw-bold text-luminous-blue">Tsh 5,000</td><td><button class="btn btn-sm btn-outline-luminous">Subscribe</button></td></tr>
                    <tr><td><strong>10 Mbps</strong></td><td>WISP</td><td class="fw-bold text-luminous-orange">Tsh 15,000</td><td><button class="btn btn-sm btn-outline-luminous">Subscribe</button></td></tr>
                    <tr><td><strong>20 Mbps</strong></td><td>WISP / AC Series</td><td class="fw-bold text-luminous-blue">Tsh 30,000</td><td><button class="btn btn-sm btn-outline-luminous">Subscribe</button></td></tr>
                    <tr class="highlight-max-yellow"><td><strong>30 Mbps</strong></td><td>WISP Optimized</td><td class="fw-bold">Tsh 50,000</td><td><button class="btn btn-sm btn-luminous">Best Value</button></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <footer class="bg-light text-dark pt-5 pb-3 border-top" id="contact">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-4">
                    <h4 class="text-navy-blue">OneLink Technologies</h4>
                    <p class="text-secondary">Your local experts for WISP fiber setups in sirari. Reliable, fast, and affordable internet.</p>
                </div>
                <div class="col-lg-4">
                    <h5>Quick Links</h5>
                    <ul class="list-unstyled">
                        <li><a href="#" class="text-decoration-none text-secondary">WISP Setup Guide</a></li>
                        <li><a href="#packages" class="text-decoration-none text-secondary">Fiber Packages</a></li>
                        <li><a href="#contact" class="text-decoration-none text-secondary">Contact Support</a></li>
                    </ul>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <h5>Follow Us</h5>
                    <div class="d-flex gap-3 justify-content-lg-end">
                        <i class="fab fa-facebook-f text-luminous-blue"></i>
                        <i class="fab fa-instagram text-luminous-pink"></i>
                        <i class="fab fa-whatsapp text-luminous-green"></i>
                    </div>
                </div>
            </div>
            <hr>
            <p class="text-center text-muted small">© 2026 OneLink Data Fiber Technologies. Optimized for WISP Solutions.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        window.addEventListener('load', function() {
            document.getElementById('spinner').style.display = 'none';
        });
    </script>
</body>
</html>
