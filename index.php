<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GPS Vehicle Tracking System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f0f2f5;
        }
        .hero-bg {
            background-color: #1a202c;
            background-image: radial-gradient(circle at 1px 1px, rgba(255,255,255,0.1) 1px, transparent 0);
            background-size: 2rem 2rem;
        }
    </style>
</head>
<body>
    <div class="min-h-screen flex flex-col">
        <!-- Header -->
        <header class="bg-white/80 backdrop-blur-md shadow-sm fixed w-full z-20">
            <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-16">
                    <div class="flex items-center">
                        <img src="icons/avlogo.png" alt="Company Logo" class="h-8 w-auto">
                        <span class="ml-3 text-xl font-bold text-gray-800">Vehicle Tracking</span>
                    </div>
                    <div class="flex items-center">
                        <a href="login.php" class="text-sm font-medium text-gray-600 hover:text-red-600">Admin Login</a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="flex-grow">
            <div class="hero-bg pt-16">
                <div class="container mx-auto px-4 sm:px-6 lg:px-8 py-20 sm:py-24 lg:py-32 text-center">
                    <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-white tracking-tight">
                        Real-Time Vehicle Tracking
                    </h1>
                    <p class="mt-6 max-w-3xl mx-auto text-lg text-gray-300">
                        Enter your tracking ID to get the live location of your vehicle instantly.
                    </p>
                    
                    <div class="mt-10 max-w-xl mx-auto">
                        <div class="bg-white rounded-xl shadow-2xl p-6 sm:p-8">
                            <form id="public-track-form">
                                <label for="plate-number-input" class="sr-only">Tracking ID</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd" />
                                        </svg>
                                    </div>
                                    <input type="text" id="plate-number-input" placeholder="Enter Plate Number / Tracking ID" required class="w-full pl-10 pr-4 py-3 text-lg border-gray-300 rounded-md shadow-sm focus:ring-red-500 focus:border-red-500">
                                </div>
                                <button type="submit" class="mt-4 w-full bg-red-600 text-white font-bold py-3 px-4 rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition duration-300 ease-in-out transform hover:scale-105">
                                    Track Now
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Result Section -->
            <div id="track-result-container" class="container mx-auto px-4 sm:px-6 lg:px-8 -mt-16" style="display: none;">
                 <div class="max-w-4xl mx-auto bg-white rounded-xl shadow-lg p-6 sm:p-8">
                    <div id="track-error" class="text-center font-medium p-4 rounded-md" style="display: none;"></div>
                    <div id="track-map-wrapper" style="display: none;">
                        <h3 class="text-2xl font-bold text-gray-800 mb-4">Live Location</h3>
                        <div id="track-map" class="h-96 w-full rounded-lg border border-gray-200"></div>
                        <div id="track-details" class="mt-4 bg-gray-50 p-4 rounded-lg"></div>
                    </div>
                 </div>
            </div>
            
            <!-- Features Section -->
            <div class="bg-gray-50 py-20 sm:py-24">
                <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="text-center">
                         <h2 class="text-3xl font-extrabold text-gray-900">Comprehensive Fleet Management</h2>
                         <p class="mt-4 max-w-2xl mx-auto text-xl text-gray-500">Everything you need to monitor and manage your operations efficiently.</p>
                    </div>
                    <div class="mt-12 grid gap-10 sm:grid-cols-2 lg:grid-cols-3">
                        <div class="pt-6">
                            <div class="flow-root bg-white rounded-lg px-6 pb-8 shadow-md">
                                <div class="-mt-6">
                                    <div>
                                        <span class="inline-flex items-center justify-center p-3 bg-red-500 rounded-md shadow-lg">
                                            <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                        </span>
                                    </div>
                                    <h3 class="mt-8 text-lg font-medium text-gray-900 tracking-tight">Live GPS Tracking</h3>
                                    <p class="mt-5 text-base text-gray-500">Monitor your entire fleet in real-time on an interactive map with live status updates.</p>
                                </div>
                            </div>
                        </div>
                        <div class="pt-6">
                            <div class="flow-root bg-white rounded-lg px-6 pb-8 shadow-md">
                                <div class="-mt-6">
                                    <div>
                                        <span class="inline-flex items-center justify-center p-3 bg-red-500 rounded-md shadow-lg">
                                            <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" /></svg>
                                        </span>
                                    </div>
                                    <h3 class="mt-8 text-lg font-medium text-gray-900 tracking-tight">Trip Ticketing System</h3>
                                    <p class="mt-5 text-base text-gray-500">Create, manage, and approve travel tickets with a full workflow and printable outputs.</p>
                                </div>
                            </div>
                        </div>
                        <div class="pt-6">
                            <div class="flow-root bg-white rounded-lg px-6 pb-8 shadow-md">
                                <div class="-mt-6">
                                    <div>
                                        <span class="inline-flex items-center justify-center p-3 bg-red-500 rounded-md shadow-lg">
                                            <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                        </span>
                                    </div>
                                    <h3 class="mt-8 text-lg font-medium text-gray-900 tracking-tight">Route History & Replay</h3>
                                    <p class="mt-5 text-base text-gray-500">Review historical trip data, view the exact path taken, and animate the route for detailed analysis.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer class="bg-gray-800">
            <div class="container mx-auto py-4 px-4 sm:px-6 lg:px-8">
                <p class="text-center text-sm text-gray-400">&copy; <?php echo date("Y"); ?> Vehicle Tracking System. All rights reserved.</p>
            </div>
        </footer>
    </div>
    <script>
        const trackForm = document.getElementById('public-track-form');
        const plateInput = document.getElementById('plate-number-input');
        const resultContainer = document.getElementById('track-result-container');
        const errorDiv = document.getElementById('track-error');
        const mapWrapper = document.getElementById('track-map-wrapper');
        const mapDiv = document.getElementById('track-map');
        const detailsDiv = document.getElementById('track-details');
        let trackMap = null;

        trackForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const plateNumber = plateInput.value.trim();
            if (!plateNumber) return;

            resultContainer.style.display = 'block';
            mapWrapper.style.display = 'none';
            errorDiv.style.display = 'block';
            errorDiv.textContent = 'Tracking...';
            errorDiv.className = 'text-center font-medium p-4 rounded-md bg-blue-100 text-blue-700';

            const formData = new FormData();
            formData.append('plate_number', plateNumber);

            fetch('public_track.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    errorDiv.style.display = 'none';
                    mapWrapper.style.display = 'block';

                    if (trackMap) trackMap.remove();
                    trackMap = L.map(mapDiv).setView([data.lat, data.lon], 16);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(trackMap);
                    L.marker([data.lat, data.lon]).addTo(trackMap)
                        .bindPopup(`<b>${data.deviceName}</b><br>${data.plateNumber}`).openPopup();
                    
                    detailsDiv.innerHTML = `
                        <p><strong>Vehicle:</strong> ${data.deviceName}</p>
                        <p><strong>Plate No:</strong> ${data.plateNumber}</p>
                        <p><strong>Last Updated:</strong> ${new Date(data.serverTime).toLocaleString()}</p>
                    `;
                } else {
                    mapWrapper.style.display = 'none';
                    errorDiv.textContent = data.message || 'An unknown error occurred.';
                    errorDiv.className = 'text-center font-medium p-4 rounded-md bg-red-100 text-red-700';
                }
            })
            .catch(err => {
                mapWrapper.style.display = 'none';
                errorDiv.textContent = 'Network error. Please try again.';
                errorDiv.className = 'text-center font-medium p-4 rounded-md bg-red-100 text-red-700';
            });
        });
    </script>
</body>
</html>
