/**
 * Footprint — scan loop and chart wiring.
 *
 * @copyright (C) 2026 Janich Rasmussen
 * @license   GNU General Public License version 2 or later
 */
(function () {
  'use strict';

  const options = Joomla.getOptions('com_footprint') || {};

  /**
   * Categorical palette (validated for CVD-safe adjacency, light and dark
   * steps of the same hues).
   */
  const PALETTE = {
    light: ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'],
    dark: ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767'],
  };

  /**
   * Fixed entity colors: blue always means files/disk, teal always means
   * database — across bars, sparklines and the growth chart.
   */
  const ROLES = {
    light: { files: '#2a78d6', db: '#1baf7a' },
    dark: { files: '#3987e5', db: '#199e70' },
  };

  /**
   * Colour for the n-th categorical slice.
   *
   * The eight base hues are validated for colour-blind separation; past
   * that they repeat, so each further lap is blended toward the surface to
   * a different degree. Slice 9 is a lighter blue than slice 1 rather than
   * the same blue twice — not as distinct as a fresh hue, but never two
   * identical slices in one ring.
   */
  const categorical = (index, palette, surface) => {
    const base = palette[index % palette.length];
    const lap = Math.floor(index / palette.length);

    if (lap === 0) {
      return base;
    }

    // 45% toward the surface on lap 2, 70% on lap 3 and beyond.
    return mix(base, surface, lap === 1 ? 0.45 : 0.7);
  };

  /**
   * Blend two hex colours; ratio 0 keeps `from`, 1 returns `to`.
   */
  const mix = (from, to, ratio) => {
    const parse = (hex) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
    const [r1, g1, b1] = parse(from);
    const [r2, g2, b2] = parse(to);
    const channel = (a, b) => Math.round(a + (b - a) * ratio).toString(16).padStart(2, '0');

    return `#${channel(r1, r2)}${channel(g1, g2)}${channel(b1, b2)}`;
  };

  const isDark = () => {
    const explicit = document.documentElement.dataset.bsTheme
      || document.documentElement.dataset.colorScheme;

    if (explicit) {
      return explicit === 'dark';
    }

    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  };

  const formatBytes = (bytes) => {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = Math.abs(bytes);
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
      value /= 1024;
      unit += 1;
    }

    return `${value.toFixed(value >= 100 || unit === 0 ? 0 : 1)} ${units[unit]}`;
  };

  const formatValue = (value, format) => {
    if (format === 'bytes') {
      return formatBytes(value);
    }

    if (format === 'percent') {
      return `${value > 0 ? '+' : ''}${value.toFixed(1)} %`;
    }

    return new Intl.NumberFormat().format(value);
  };

  /**
   * Values for a series in the requested mode. Percent mode indexes each
   * series against its first non-zero value in the window.
   */
  const seriesValues = (values, mode) => {
    if (mode !== 'pct') {
      return values;
    }

    const base = values.find((value) => value > 0) || 0;

    return values.map((value) => (base > 0 ? ((value / base) - 1) * 100 : 0));
  };

  /**
   * Build an HTML legend beside a doughnut, mirroring Chart.js behaviour:
   * clicking an entry toggles that slice. Laid out by CSS multi-column, so
   * the entries split evenly between columns instead of overflowing one.
   */
  const buildLegend = (chart, colors, config) => {
    const list = document.querySelector(`[data-footprint-legend="${chart.canvas.id}"]`);

    if (!list) {
      return;
    }

    list.textContent = '';

    // One column reads better until the list gets long enough that a single
    // column would tower over the doughnut beside it.
    list.style.setProperty('--footprint-legend-columns', config.labels.length > 8 ? 2 : 1);

    config.labels.forEach((label, index) => {
      const item = document.createElement('li');
      const button = document.createElement('button');

      button.type = 'button';
      button.className = 'footprint-legend-item';
      button.innerHTML = `<span class="footprint-legend-swatch" style="background:${colors[index]}"></span>`;
      button.append(document.createTextNode(label));

      button.addEventListener('click', () => {
        chart.toggleDataVisibility(index);
        button.classList.toggle('is-hidden', !chart.getDataVisibility(index));
        chart.update();
      });

      item.append(button);
      list.append(item);
    });
  };

  /**
   * Render every chart declared in the script options.
   */
  const renderCharts = () => {
    if (!options.charts || typeof window.Chart === 'undefined') {
      return;
    }

    const dark = isDark();
    const palette = dark ? PALETTE.dark : PALETTE.light;
    const roles = dark ? ROLES.dark : ROLES.light;
    const surface = dark ? '#212529' : '#ffffff';
    const grid = dark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.06)';
    const bodyStyle = window.getComputedStyle(document.body);
    const ink = bodyStyle.color || (dark ? '#f8f9fa' : '#212529');

    Object.entries(options.charts).forEach(([id, config]) => {
      const canvas = document.getElementById(id);

      if (!canvas) {
        return;
      }

      const common = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          tooltip: {
            callbacks: {
              label: (context) => {
                const value = context.parsed.x ?? context.parsed;
                const numeric = typeof value === 'object' ? value.r : value;
                return ` ${formatValue(numeric, config.format)}`;
              },
            },
          },
        },
      };

      if (config.type === 'doughnut') {
        const colors = config.labels.map((label, i) => categorical(i, palette, surface));

        const chart = new window.Chart(canvas, {
          type: 'doughnut',
          data: {
            labels: config.labels,
            datasets: [{
              data: config.values,
              backgroundColor: colors,
              // 2px surface gap between segments.
              borderColor: surface,
              borderWidth: 2,
            }],
          },
          options: {
            ...common,
            cutout: '55%',
            plugins: {
              ...common.plugins,
              // Chart.js fills its first legend column to the available
              // height and spills the rest, which reads as lopsided. We
              // render our own so CSS can balance the columns evenly.
              legend: { display: false },
            },
          },
        });

        buildLegend(chart, colors, config);

        return;
      }

      if (config.type === 'line') {
        const mode = window.localStorage.getItem('footprint.growthMode') === 'pct' ? 'pct' : 'abs';

        const renderLine = (activeMode) => {
          const format = activeMode === 'pct' ? 'percent' : config.format;

          return new window.Chart(canvas, {
            type: 'line',
            data: {
              labels: config.labels,
              datasets: (config.series || []).map((series) => ({
                label: series.label,
                data: seriesValues(series.values, activeMode),
                borderColor: roles[series.role] || palette[0],
                backgroundColor: roles[series.role] || palette[0],
                borderWidth: 2,
                borderDash: series.dashed ? [5, 4] : [],
                pointRadius: 2,
                pointHoverRadius: 4,
                tension: 0.2,
              })),
            },
            options: {
              ...common,
              interaction: { mode: 'index', intersect: false },
              plugins: {
                ...common.plugins,
                legend: {
                  position: 'bottom',
                  labels: { color: ink, boxWidth: 12, boxHeight: 3 },
                },
                tooltip: {
                  callbacks: {
                    label: (context) => ` ${context.dataset.label}: ${formatValue(context.parsed.y, format)}`,
                  },
                },
              },
              scales: {
                x: {
                  ticks: { color: ink, maxTicksLimit: 8 },
                  grid: { display: false },
                },
                y: {
                  ticks: {
                    color: ink,
                    callback: (value) => formatValue(value, format),
                  },
                  grid: { color: grid },
                },
              },
            },
          });
        };

        let chart = renderLine(mode);

        const toggle = document.querySelector('[data-footprint-growth-toggle]');

        if (toggle) {
          const buttons = toggle.querySelectorAll('[data-growth-mode]');

          buttons.forEach((button) => {
            button.classList.toggle('active', button.dataset.growthMode === mode);

            button.addEventListener('click', () => {
              const nextMode = button.dataset.growthMode;

              buttons.forEach((other) => other.classList.toggle('active', other === button));
              window.localStorage.setItem('footprint.growthMode', nextMode);

              chart.destroy();
              chart = renderLine(nextMode);
            });
          });
        }

        return;
      }

      const barOptions = (stacked) => ({
        ...common,
        indexAxis: 'y',
        plugins: {
          ...common.plugins,
          legend: stacked
            ? { position: 'bottom', labels: { color: ink, boxWidth: 12, boxHeight: 12 } }
            : { display: false },
          tooltip: {
            callbacks: {
              label: (context) => ` ${context.dataset.label ? `${context.dataset.label}: ` : ''}${formatValue(context.parsed.x, config.format)}`,
              footer: stacked
                ? (items) => formatValue(items.reduce((sum, item) => sum + item.parsed.x, 0), config.format)
                : undefined,
            },
          },
        },
        scales: {
          x: {
            stacked,
            ticks: {
              color: ink,
              callback: (value) => formatValue(value, config.format),
            },
            grid: { color: grid },
          },
          y: {
            stacked,
            ticks: { color: ink },
            grid: { display: false },
          },
        },
      });

      // Extensions chart: fixed measure chosen by an explicit toggle, so
      // sorting the table below never changes what the chart means.
      if (config.measures) {
        const stored = window.localStorage.getItem('footprint.measure');
        let measure = ['total', 'disk', 'db'].indexOf(stored) > -1 ? stored : 'total';

        const valueOf = (row, mode) => (mode === 'disk' ? row.disk : (mode === 'db' ? row.db : row.disk + row.db));

        const renderBar = (mode) => {
          const rows = (config.rows || [])
            .filter((row) => valueOf(row, mode) > 0)
            .sort((a, b) => valueOf(b, mode) - valueOf(a, mode))
            .slice(0, config.topN || 10);

          const labels = rows.map((row) => row.label);
          const bar = { borderRadius: 4, barThickness: 14 };

          const datasets = mode === 'total'
            ? [
              // 2px surface gap between the stacked segments.
              { ...bar, label: config.diskLabel, data: rows.map((row) => row.disk), backgroundColor: roles.files, borderColor: surface, borderWidth: { right: 2 } },
              { ...bar, label: config.dbLabel, data: rows.map((row) => row.db), backgroundColor: roles.db },
            ]
            : [{ ...bar, data: rows.map((row) => valueOf(row, mode)), backgroundColor: mode === 'db' ? roles.db : roles.files }];

          const wrap = canvas.closest('.footprint-chart-wrap');

          if (wrap) {
            wrap.style.setProperty('--footprint-chart-rows', Math.max(1, rows.length));
          }

          return new window.Chart(canvas, {
            type: 'bar',
            data: { labels, datasets },
            options: barOptions(mode === 'total'),
          });
        };

        let chart = renderBar(measure);
        const toggle = document.querySelector('[data-footprint-measure-toggle]');

        if (toggle) {
          const buttons = toggle.querySelectorAll('[data-measure]');

          buttons.forEach((button) => {
            button.classList.toggle('active', button.dataset.measure === measure);

            button.addEventListener('click', () => {
              measure = button.dataset.measure;
              buttons.forEach((other) => other.classList.toggle('active', other === button));
              window.localStorage.setItem('footprint.measure', measure);

              chart.destroy();
              chart = renderBar(measure);
            });
          });
        }

        return;
      }

      // Plain horizontal bar: single measure, single hue per entity role.
      new window.Chart(canvas, {
        type: 'bar',
        data: {
          labels: config.labels,
          datasets: [{
            data: config.values,
            backgroundColor: roles[config.role] || palette[0],
            borderRadius: 4,
            barThickness: 14,
          }],
        },
        options: barOptions(false),
      });
    });
  };

  /**
   * Run the chunked scan loop: call scan.run until {done: true}.
   *
   * Each request walks about a second of filesystem, so updates land
   * roughly every second. Between updates the bar creeps forward on its
   * own — a long directory should never look like a frozen scan.
   */
  const runScan = async (progressEl) => {
    const bar = progressEl ? progressEl.querySelector('.progress-bar') : null;
    const meter = progressEl ? progressEl.querySelector('.progress') : null;
    const text = progressEl ? progressEl.querySelector('[data-footprint-progress-text]') : null;
    const percent = progressEl ? progressEl.querySelector('[data-footprint-progress-percent]') : null;

    if (progressEl) {
      progressEl.classList.remove('d-none');
    }

    let shown = 0;
    // A nudge so the bar moves the instant the button is pressed; the first
    // chunk also builds the extension map, so it is the slowest one.
    let target = 3;

    const paint = () => {
      if (bar) {
        bar.style.width = `${shown.toFixed(1)}%`;
      }

      if (meter) {
        meter.setAttribute('aria-valuenow', Math.round(shown));
      }

      if (percent) {
        percent.textContent = `${Math.round(shown)} %`;
      }
    };

    // Creep towards the last known target so the bar keeps moving between
    // responses, easing off as it approaches it.
    const creep = window.setInterval(() => {
      if (shown < target) {
        shown = Math.min(target, shown + Math.max(0.15, (target - shown) * 0.08));
        paint();
      }
    }, 100);

    try {
      let done = false;
      let guard = 2000;

      while (!done && guard-- > 0) {
        const response = await fetch(options.scanUrl, {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!response.ok) {
          throw new Error(`Scan request failed: ${response.status}`);
        }

        const json = await response.json();
        const data = json.data || {};

        done = !!data.done;

        if (typeof data.progress === 'number') {
          target = Math.min(100, data.progress * 100);
        }

        if (text && data.current) {
          text.textContent = (options.scanningLabel || 'Scanning %s').replace('%s', data.current);
        }

        if (done) {
          shown = 100;
          target = 100;
          paint();

          if (text) {
            text.textContent = options.finishingLabel || '';
          }
        }
      }
    } finally {
      window.clearInterval(creep);
    }

    window.location.reload();
  };

  document.addEventListener('DOMContentLoaded', () => {
    renderCharts();

    const progressEl = document.querySelector('[data-footprint-progress]');

    document.querySelectorAll('[data-footprint-scan]').forEach((button) => {
      button.addEventListener('click', () => {
        button.disabled = true;
        runScan(progressEl).catch((error) => {
          button.disabled = false;
          Joomla.renderMessages({ error: [error.message] });
        });
      });
    });
  });
})();
