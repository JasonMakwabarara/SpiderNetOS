import React, { useEffect, useRef } from 'react';

// Animated SVG node-link network graph used as hero background
export default function NetworkGraph({ className = '' }) {
  const ref = useRef(null);

  useEffect(() => {
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const svg = ref.current;
    if (!svg || reduce) return;
    const lines = svg.querySelectorAll('line');
    let raf;
    let t = 0;
    const loop = () => {
      t += 0.012;
      lines.forEach((l, i) => {
        const o = 0.05 + 0.18 * Math.abs(Math.sin(t + i * 0.31));
        l.setAttribute('stroke-opacity', o.toFixed(3));
      });
      raf = requestAnimationFrame(loop);
    };
    raf = requestAnimationFrame(loop);
    return () => cancelAnimationFrame(raf);
  }, []);

  // Pre-computed node grid for crisp rendering
  const cols = 9;
  const rows = 6;
  const w = 1200;
  const h = 700;
  const nodes = [];
  for (let r = 0; r < rows; r++) {
    for (let c = 0; c < cols; c++) {
      // jitter for organic feel — deterministic
      const jx = ((r * 7 + c * 13) % 11) - 5;
      const jy = ((r * 11 + c * 5) % 9) - 4;
      nodes.push({
        x: (c + 0.5) * (w / cols) + jx * 4,
        y: (r + 0.5) * (h / rows) + jy * 4,
      });
    }
  }
  // build edges between neighbors
  const edges = [];
  for (let r = 0; r < rows; r++) {
    for (let c = 0; c < cols; c++) {
      const i = r * cols + c;
      if (c < cols - 1) edges.push([i, i + 1]);
      if (r < rows - 1) edges.push([i, i + cols]);
      if (c < cols - 1 && r < rows - 1) {
        if ((r + c) % 2 === 0) edges.push([i, i + cols + 1]);
      }
    }
  }

  return (
    <svg
      ref={ref}
      className={`absolute inset-0 w-full h-full ${className}`}
      viewBox={`0 0 ${w} ${h}`}
      preserveAspectRatio="xMidYMid slice"
      aria-hidden
    >
      <defs>
        <radialGradient id="ng-center" cx="50%" cy="50%" r="60%">
          <stop offset="0%" stopColor="#00D6C9" stopOpacity="0.18" />
          <stop offset="100%" stopColor="#070A12" stopOpacity="0" />
        </radialGradient>
        <linearGradient id="ng-edge" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor="#FF6B2C" stopOpacity="0.55" />
          <stop offset="1" stopColor="#00D6C9" stopOpacity="0.55" />
        </linearGradient>
      </defs>
      <rect width={w} height={h} fill="url(#ng-center)" />
      <g>
        {edges.map(([a, b], idx) => (
          <line
            key={idx}
            x1={nodes[a].x}
            y1={nodes[a].y}
            x2={nodes[b].x}
            y2={nodes[b].y}
            stroke="url(#ng-edge)"
            strokeWidth="0.8"
            strokeOpacity="0.12"
          />
        ))}
      </g>
      <g>
        {nodes.map((n, i) => {
          const cx = i % 17 === 0 ? '#FF6B2C' : i % 11 === 0 ? '#00D6C9' : '#F4F7FB';
          const op = i % 17 === 0 ? 1 : i % 11 === 0 ? 0.85 : 0.32;
          const rad = i % 17 === 0 ? 2.6 : i % 11 === 0 ? 2.2 : 1.4;
          return <circle key={i} cx={n.x} cy={n.y} r={rad} fill={cx} fillOpacity={op} />;
        })}
      </g>
    </svg>
  );
}
