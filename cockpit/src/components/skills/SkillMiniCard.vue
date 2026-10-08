<template>
  <article
    class="sn-card p-4 flex flex-col gap-3"
    :data-testid="`skill-mini-${skill.slug}`"
    :data-stage="stage"
    :data-enabled="skill.enabled ? 'true' : 'false'"
  >
    <header class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <div class="flex items-center gap-2 min-w-0">
          <span
            class="inline-block size-2 rounded-full shrink-0"
            :style="{ background: stageInfo.color }"
            :title="`${stageInfo.label}${skill.pipeline?.inherited ? ' (inherited from tenant)' : ''}`"
            :data-testid="`skill-stage-dot-${skill.slug}`"
            :data-stage="stage"
            aria-hidden="true"
          />
          <span class="sr-only">Stage: {{ stageInfo.label }}.</span>
          <RouterLink
            :to="`/skills/${skill.slug}`"
            class="text-sm font-semibold truncate hover:underline"
            style="color: var(--text-primary);"
            :data-testid="`skill-title-${skill.slug}`"
          >{{ skill.name }}</RouterLink>
        </div>
        <p class="text-[11px] mt-0.5 truncate" style="color: var(--text-muted);">
          <span v-if="skill.node?.label">{{ skill.node.label }} · </span>{{ skill.identity?.name || '—' }} → {{ agent.name }}
        </p>
      </div>
      <span
        class="sn-pill shrink-0"
        :class="skill.enabled ? 'sn-pill-success' : ''"
        :data-testid="`skill-enabled-${skill.slug}`"
      >{{ skill.enabled ? 'Enabled' : provisioningLabel }}</span>
    </header>

    <p class="text-sm" style="color: var(--text-secondary);">{{ skill.one_liner }}</p>

    <div class="flex items-center justify-between gap-2 mt-auto flex-wrap">
      <span
        class="sn-pill"
        :class="skill.brain?.ready ? 'sn-pill-success' : 'sn-pill-warn'"
        :title="brainTitle"
        :data-testid="`skill-brain-pill-${skill.slug}`"
      >{{ brainLabel }}</span>

      <div class="flex items-center gap-1.5">
        <RouterLink
          v-if="skill.provisioning === 'requires_pack'"
          :to="installPath"
          class="sn-btn-secondary text-xs"
          style="padding: 0.3rem 0.6rem;"
          :data-testid="`skill-install-${skill.slug}`"
        >Install pack</RouterLink>
        <button
          v-else-if="!skill.enabled"
          type="button"
          class="sn-btn-secondary text-xs"
          style="padding: 0.3rem 0.6rem; color: var(--accent); border-color: rgba(0,214,201,0.30);"
          :disabled="busy"
          :data-testid="`skill-enable-${skill.slug}`"
          @click="emit('enable', skill)"
        >{{ busy ? 'Enabling…' : 'Enable' }}</button>
        <RouterLink
          :to="`/skills/${skill.slug}`"
          class="sn-btn text-xs"
          style="padding: 0.3rem 0.6rem;"
          :data-testid="`skill-open-${skill.slug}`"
        >Open</RouterLink>
      </div>
    </div>

    <p
      v-if="notice"
      class="text-[11px]"
      :style="{ color: noticeError ? 'var(--danger)' : 'var(--accent)' }"
      :data-testid="`skill-notice-${skill.slug}`"
    >{{ notice }}</p>
  </article>
</template>

<script setup>
/**
 * SkillMiniCard — one tile in the roster (surface B). Stage dot mirrors
 * the pipeline stage tokens, the brain pill says whether every required
 * file is filled, and the action is one of Open / Enable / Install pack
 * depending on tenant state. Enabling is the parent's job (emit).
 */
import { computed } from 'vue'
import { stageMeta, coreAgent } from '../../utils/format.js'

const props = defineProps({
  skill:       { type: Object, required: true },
  busy:        { type: Boolean, default: false },
  notice:      { type: String, default: '' },
  noticeError: { type: Boolean, default: false },
})

const emit = defineEmits(['enable'])

const stage = computed(() => props.skill.pipeline?.stage || 'human_led')
const stageInfo = computed(() => stageMeta(stage.value))
const agent = computed(() => coreAgent(props.skill.core_agent))

const provisioningLabel = computed(() => {
  if (props.skill.provisioning === 'requires_pack') return 'Needs pack'
  if (props.skill.provisioning === 'on_demand') return 'On demand'
  return 'Not enabled'
})

const missing = computed(() => props.skill.brain?.missing || [])
const brainLabel = computed(() => {
  if (props.skill.brain?.ready) return 'Brain ready'
  const n = missing.value.length
  return n ? `${n} brain file${n === 1 ? '' : 's'} missing` : 'Brain incomplete'
})
const brainTitle = computed(() => (missing.value.length ? `Missing: ${missing.value.join(', ')}` : 'Every required brain file is filled.'))

const installPath = computed(() => {
  const packId = props.skill.requires_pack?.pack_id || props.skill.requires_pack?.id
  return packId ? { path: '/feature-packs', hash: `#pack-${packId}` } : '/feature-packs'
})
</script>
