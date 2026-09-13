<script setup lang="ts">
/**
 * The one reusable "pick an agent" dropdown for super_admin — populated from
 * GET /admin/agents (listAgentDirectory, already used by Kontak Agen). Every
 * spot that used to need a super_admin to type a raw numeric agent id
 * (Stock filter, User create-form) should bind here instead.
 */
import { ref, onMounted } from 'vue'
import { listAgentDirectory, type AgentDirectoryRow } from '@/api/agents'

const props = withDefaults(
  defineProps<{
    modelValue: number | null | undefined
    /** Show a "Semua Agen" (value null) option — for report/listing filters. Set false for a required create-form field. */
    allowAll?: boolean
    label?: string
  }>(),
  { allowAll: true, label: 'Agen' },
)

const emit = defineEmits<{ 'update:modelValue': [number | null] }>()

const agents = ref<AgentDirectoryRow[]>([])
onMounted(async () => {
  agents.value = await listAgentDirectory()
})

function onChange(event: Event) {
  const value = (event.target as HTMLSelectElement).value
  emit('update:modelValue', value ? Number(value) : null)
}
</script>

<template>
  <label class="text-sm text-stone-600 dark:text-stone-300">
    {{ label }}
    <select
      :value="props.modelValue ?? ''"
      class="mt-1 block rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
      @change="onChange"
    >
      <option v-if="allowAll" value="">Semua Agen</option>
      <option v-else value="" disabled>Pilih agen...</option>
      <option v-for="agent in agents" :key="agent.user_id" :value="agent.user_id">
        {{ agent.profile?.store_name ?? agent.name }}
      </option>
    </select>
  </label>
</template>
