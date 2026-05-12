<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Offline | Accounts Bazar</title>
	<style>
		:root {
			--bg-1: #f6f9ff;
			--bg-2: #eef4ff;
			--card: #ffffff;
			--text: #10203a;
			--muted: #4d607d;
			--accent: #0d6efd;
			--accent-dark: #0b5ed7;
			--ring: rgba(13, 110, 253, 0.25);
		}

		* {
			box-sizing: border-box;
		}

		body {
			margin: 0;
			min-height: 100vh;
			display: grid;
			place-items: center;
			font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
			color: var(--text);
			background:
				radial-gradient(circle at 20% 15%, #dbe8ff 0, transparent 40%),
				radial-gradient(circle at 80% 85%, #d8f1ff 0, transparent 45%),
				linear-gradient(140deg, var(--bg-1), var(--bg-2));
			padding: 24px;
		}

		.card {
			width: min(640px, 100%);
			background: var(--card);
			border-radius: 16px;
			box-shadow: 0 12px 40px rgba(16, 32, 58, 0.12);
			padding: 30px 24px;
			text-align: center;
			border: 1px solid #e7efff;
			animation: fadeInUp 0.45s ease;
		}

		.status {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			background: #fff4f4;
			color: #b42318;
			border: 1px solid #ffd7d7;
			border-radius: 999px;
			padding: 8px 14px;
			font-weight: 600;
			font-size: 14px;
			margin-bottom: 14px;
		}

		h1 {
			margin: 0 0 12px;
			font-size: clamp(1.4rem, 2.6vw, 2rem);
			line-height: 1.25;
		}

		p {
			margin: 0 auto 20px;
			color: var(--muted);
			max-width: 52ch;
			line-height: 1.65;
		}

		.actions {
			display: flex;
			flex-wrap: wrap;
			justify-content: center;
			gap: 12px;
		}

		.btn {
			border: 0;
			border-radius: 10px;
			padding: 11px 18px;
			text-decoration: none;
			cursor: pointer;
			font-weight: 600;
			font-size: 14px;
			transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
		}

		.btn-primary {
			color: #fff;
			background: var(--accent);
			box-shadow: 0 8px 18px var(--ring);
		}

		.btn-primary:hover {
			background: var(--accent-dark);
			transform: translateY(-1px);
		}

		.btn-secondary {
			color: var(--text);
			background: #eef3ff;
		}

		.btn-secondary:hover {
			background: #e4ecff;
			transform: translateY(-1px);
		}

		.tips {
			margin-top: 18px;
			text-align: left;
			background: #f8fbff;
			border: 1px solid #e6efff;
			border-radius: 12px;
			padding: 14px;
			color: var(--muted);
			font-size: 14px;
		}

		.tips ul {
			margin: 8px 0 0 18px;
			padding: 0;
		}

		.tips li {
			margin-bottom: 6px;
		}

		@keyframes fadeInUp {
			from {
				opacity: 0;
				transform: translateY(10px);
			}
			to {
				opacity: 1;
				transform: translateY(0);
			}
		}
	</style>
</head>
<body>
	<main class="card" role="main" aria-labelledby="offlineTitle">
		<div class="status" id="networkStatus">No internet connection</div>
		<h1 id="offlineTitle">You are currently offline</h1>
		<p>
			It looks like your internet connection is unavailable right now.
			Please reconnect and try again.
		</p>

		<div class="actions">
			<button class="btn btn-primary" type="button" onclick="window.location.reload()">Try again</button>
			<a class="btn btn-secondary" href="../index.php">Go to Home</a>
		</div>

		<section class="tips" aria-label="Troubleshooting tips">
			<strong>Quick checks:</strong>
			<ul>
				<li>Turn Wi-Fi or mobile data on.</li>
				<li>Restart your router if needed.</li>
				<li>Try refreshing after reconnecting.</li>
			</ul>
		</section>
	</main>

	<script>
		(function () {
			var statusEl = document.getElementById('networkStatus');

			function updateStatus() {
				if (navigator.onLine) {
					statusEl.textContent = 'Connection restored';
					statusEl.style.background = '#ecfdf3';
					statusEl.style.color = '#067647';
					statusEl.style.borderColor = '#b7ebcd';
				} else {
					statusEl.textContent = 'No internet connection';
					statusEl.style.background = '#fff4f4';
					statusEl.style.color = '#b42318';
					statusEl.style.borderColor = '#ffd7d7';
				}
			}

			window.addEventListener('online', function () {
				updateStatus();
				setTimeout(function () {
					window.location.href = '../index.php';
				}, 500);
			});

			window.addEventListener('offline', updateStatus);
			updateStatus();
		})();
	</script>
</body>
</html>
