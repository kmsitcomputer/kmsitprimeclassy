<script setup lang="ts">
/**
 * PC dashboard bar chart (Premium Maroon) — dashboard-only component.
 *
 * Renders REAL data passed in by the parent view; this component performs no
 * fetching and fabricates nothing. When `labels`/`values` are empty the parent
 * is expected to show its own unavailable/empty state instead of mounting us.
 *
 * Colours come from design/design-tokens.json (premium-maroon) and mirror
 * the mockdesign bar gradient (0deg #8B2635 -> #DC926D).
 */
import { computed } from 'vue'
import { Bar } from 'vue-chartjs'
import {
  Chart as ChartJS,
  BarElement,
  CategoryScale,
  LinearScale,
  Tooltip,
  type ChartConfiguration,
  type ScriptableContext,
} from 'chart.js'
import { formatRupiah } from '@/utils/format'

ChartJS.register(BarElement, CategoryScale, LinearScale, Tooltip)

const props = withDefaults(
  defineProps<{
    labels: string[]
    values: number[]
    /** Screen-reader/visible context for the figure (bound as aria-label). */
    figureLabel: string
    valueFormat?: 'rupiah' | 'plain'
  }>(),
  { valueFormat: 'plain' },
)

const data = computed(() => ({
  labels: props.labels,
  datasets: [
    {
      label: props.figureLabel,
      data: props.values,
      borderRadius: 5,
      borderSkipped: false,
      maxBarThickness: 34,
      // Vertical wine -> warm sand gradient per mockdesign `.bar`.
      backgroundColor: (ctx: ScriptableContext<'bar'>) => {
        const { chart } = ctx
        const { ctx: c, chartArea } = chart
        if (!chartArea) return '#8B2635'
        const g = c.createLinearGradient(0, chartArea.bottom, 0, chartArea.top)
        g.addColorStop(0, '#8B2635')
        g.addColorStop(1, '#DC926D')
        return g
      },
      hoverBackgroundColor: '#D8A66C',
    },
  ],
}))

const options = computed<ChartConfiguration<'bar'>['options']>(() => ({
  responsive: true,
  maintainAspectRatio: false,
  interaction: { mode: 'index', intersect: false },
  scales: {
    x: {
      grid: { display: false },
      border: { color: '#68434A' },
      ticks: { color: '#C3A6A7', font: { size: 11 }, maxRotation: 0, autoSkip: true },
    },
    y: {
      beginAtZero: true,
      grid: { color: 'rgba(84, 48, 57, 0.55)' },
      border: { display: false },
      ticks: {
        color: '#C3A6A7',
        font: { size: 11 },
        callback: (value) =>
          props.valueFormat === 'rupiah'
            ? formatRupiah(Number(value)).replace(/,00$/, '')
            : String(value),
      },
    },
  },
  plugins: {
    legend: { display: false },
    tooltip: {
      backgroundColor: '#392027',
      titleColor: '#FFF5EC',
      bodyColor: '#F2D0AB',
      borderColor: '#543039',
      borderWidth: 1,
      padding: 10,
      callbacks: {
        label: (item) =>
          ` ${props.valueFormat === 'rupiah' ? formatRupiah(Number(item.parsed.y ?? 0)) : item.parsed.y}`,
      },
    },
  },
}))
</script>

<template>
  <figure class="m-0 h-[220px] w-full min-w-0" role="img" :aria-label="figureLabel">
    <Bar :data="data" :options="options" />
  </figure>
</template>
