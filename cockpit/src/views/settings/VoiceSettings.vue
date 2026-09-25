<template>
  <div class="px-6 py-7 max-w-5xl mx-auto pb-28" data-testid="voice-settings-page">
    <div class="mb-6">
      <div class="sn-eyebrow">Settings · Atlas voice</div>
      <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">Atlas voice</h1>
      <p class="text-sm mt-1 max-w-2xl" style="color: var(--text-secondary);">
        Choose how Atlas sounds when it reads back to you. Preview a voice, pick one for Atlas, and decide whether new
        replies are read aloud.
      </p>
    </div>

    <p
      v-if="showFallbackNotice"
      class="text-xs mb-5 rounded-lg px-3 py-2"
      style="color: var(--amber, var(--warn)); background: rgba(245,165,36,0.08); border: 1px solid rgba(245,165,36,0.25);"
      role="status"
      data-testid="voice-fallback-notice"
    >{{ fallbackText }}</p>

    <!-- Speaking -->
    <section class="sn-card p-5 mb-6" aria-labelledby="voice-speaking-title" data-testid="voice-speaking">
      <h2 id="voice-speaking-title" class="sn-section-title">Speaking</h2>
      <div class="mt-3 space-y-3">
        <div class="flex items-start justify-between gap-4">
          <div class="min-w-0">
            <p class="text-sm font-medium" style="color: var(--text-primary);">Read Atlas's replies aloud</p>
            <p class="text-xs mt-0.5" style="color: var(--text-muted);">New replies in the Atlas chat play automatically while the tab is open.</p>
          </div>
          <button
            type="button"
            role="switch"
            class="sn-switch shrink-0"
            :aria-checked="voice.speakEnabled ? 'true' : 'false'"
            :style="switchStyle(voice.speakEnabled)"
            aria-label="Read Atlas's replies aloud"
            data-testid="voice-speak-toggle"
            @click="onToggleSpeak"
          ><span class="sn-switch-knob" :style="knobStyle(voice.speakEnabled)" /></button>
        </div>
        <div class="flex items-start justify-between gap-4">
          <div class="min-w-0">
            <p class="text-sm font-medium" style="color: var(--text-primary);">Use the browser's voice as a fallback</p>
            <p class="text-xs mt-0.5" style="color: var(--text-muted);">
              When Atlas's voice can't be reached, your browser reads the reply instead (it prefers South African, Nigerian or Kenyan English).
            </p>
          </div>
          <button
            type="button"
            role="switch"
            class="sn-switch shrink-0"
            :aria-checked="voice.browserFallback ? 'true' : 'false'"
            :style="switchStyle(voice.browserFallback)"
            aria-label="Use the browser's voice as a fallback"
            data-testid="voice-fallback-toggle"
            @click="voice.browserFallback = !voice.browserFallback"
          ><span class="sn-switch-knob" :style="knobStyle(voice.browserFallback)" /></button>
        </div>
        <p v-if="voice.speakEnabled && voice.autoplayBlocked" class="text-xs" style="color: var(--amber, var(--warn));" data-testid="voice-blocked">
          Your browser is blocking audio. Press a Preview button once to allow it.
        </p>
      </div>
    </section>

    <!-- Personas -->
    <section aria-labelledby="voice-personas-title" data-testid="voice-personas">
      <div class="flex items-end justify-between gap-3 mb-3 flex-wrap">
        <div>
          <h2 id="voice-personas-title" class="sn-section-title">Voices</h2>
          <p class="text-xs mt-0.5" style="color: var(--text-muted);">
            Selected: <span style="color: var(--text-primary);" data-testid="voice-selected-name">{{ voice.selectedVoice?.display_name || 'none yet' }}</span>
          </p>
        </div>
        <p v-if="voice.loading" class="text-xs" style="color: var(--text-muted);" data-testid="voice-loading">Loading voices…</p>
      </div>

      <div
        v-for="group in voice.voicesByProvider"
        :key="group.provider"
        class="mb-6"
        :data-testid="`voice-provider-${group.provider}`"
      >
        <h3 class="sn-eyebrow mb-2">{{ group.label }} <span class="mono" style="color: var(--text-muted);">{{ group.voices.length }}</span></h3>
        <div class="grid md:grid-cols-2 gap-3" role="radiogroup" :aria-label="`${group.label} voices`">
          <div
            v-for="p in group.voices"
            :key="p.slug"
            class="sn-card p-4 flex flex-col gap-2 transition-colors"
            :style="{ borderColor: voice.selectedVoiceId === p.slug ? 'var(--status-live)' : undefined }"
            :data-testid="`voice-persona-${p.slug}`"
            :data-selected="voice.selectedVoiceId === p.slug ? 'true' : 'false'"
          >
            <div class="flex items-start justify-between gap-3">
              <label class="flex items-start gap-2 min-w-0 cursor-pointer">
                <input
                  type="radio"
                  name="atlas-voice"
                  class="mt-1 shrink-0"
                  :value="p.slug"
                  :checked="voice.selectedVoiceId === p.slug"
                  :data-testid="`voice-radio-${p.slug}`"
                  @change="voice.selectVoice(p.slug)"
                />
                <span class="min-w-0">
                  <span class="block text-sm font-semibold" style="color: var(--text-primary);">{{ p.display_name }}</span>
                  <span class="block text-[11px] mt-0.5" style="color: var(--text-muted);">
                    {{ [p.accent, p.gender, p.style].filter(Boolean).join(' · ') || '—' }}
                  </span>
                </span>
              </label>
              <span v-if="p.is_default" class="sn-pill sn-pill-accent shrink-0 text-[10px]" :data-testid="`voice-default-${p.slug}`">Default</span>
            </div>

            <div class="flex flex-wrap gap-1">
              <span
                v-for="tag in visibleTags(p)"
                :key="tag"
                class="sn-pill text-[10px]"
              >{{ tag }}</span>
              <span
                v-if="recommendedForAtlas(p)"
                class="sn-pill sn-pill-success text-[10px]"
                :data-testid="`voice-recommended-${p.slug}`"
              >Recommended for Atlas</span>
            </div>

            <div class="flex items-center justify-between gap-2 mt-auto pt-1">
              <span class="text-[11px]" style="color: var(--text-muted);" :data-testid="`voice-cost-${p.slug}`">{{ costLabel(p) }}</span>
              <div class="flex items-center gap-1.5">
                <button
                  type="button"
                  class="sn-btn-secondary text-xs"
                  style="padding: 0.3rem 0.6rem;"
                  :aria-label="previewing(p.slug) ? `Stop preview of ${p.display_name}` : `Preview ${p.display_name}`"
                  :data-testid="`voice-preview-${p.slug}`"
                  @click="onPreview(p.slug)"
                >{{ previewing(p.slug) ? '■ Stop' : '▶ Preview' }}</button>
                <button
                  type="button"
                  class="text-xs"
                  :class="voice.selectedVoiceId === p.slug ? 'sn-pill sn-pill-success' : 'sn-btn'"
                  :style="voice.selectedVoiceId === p.slug ? '' : 'padding: 0.3rem 0.6rem;'"
                  :disabled="voice.selectedVoiceId === p.slug"
                  :data-testid="`voice-choose-${p.slug}`"
                  @click="voice.selectVoice(p.slug)"
                >{{ voice.selectedVoiceId === p.slug ? "Atlas's voice" : 'Choose for Atlas' }}</button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <p
        v-if="!voice.loading && !voice.voices.length"
        class="text-sm"
        style="color: var(--text-muted);"
        data-testid="voice-empty"
      >No voices are available yet.</p>
    </section>

    <p v-if="voice.speakError" class="text-xs mt-2" style="color: var(--danger);" role="alert" data-testid="voice-speak-error">{{ voice.speakError }}</p>

    <!-- Save bar -->
    <div
      class="fixed bottom-0 inset-x-0 z-30 border-t"
      style="background: rgba(8,10,14,0.92); border-color: var(--border); backdrop-filter: blur(8px);"
    >
      <div class="max-w-5xl mx-auto px-6 py-3 flex items-center justify-end gap-3">
        <p
          class="text-xs mr-auto"
          :style="{ color: saveState.error ? 'var(--danger)' : 'var(--text-muted)' }"
          aria-live="polite"
          data-testid="voice-save-status"
        >{{ saveState.text }}</p>
        <button
          type="button"
          class="sn-btn text-sm"
          style="padding: 0.45rem 1rem;"
          :disabled="voice.saving"
          data-testid="voice-save"
          @click="onSave"
        >{{ voice.saving ? 'Saving…' : 'Save' }}</button>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * VoiceSettings — `/settings/voice` (plan D6-D). Persona radio cards
 * grouped by provider with preview clips, the two speaking switches and a
 * Save bar that PUTs /api/me/voice. Previews go through the voice store so
 * they share the one audio element (and its autoplay unlock).
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useVoiceStore } from '../../stores/voice.js'

const voice = useVoiceStore()

const saved = ref(null)
const saveResult = ref(null)

const showFallbackNotice = computed(() => voice.fallback || (!voice.loading && !voice.voices.length))
const fallbackText = computed(() => {
  if (voice.error) return voice.error
  return "No voices are available yet — Atlas will use your browser's voice until they are."
})

const dirty = computed(() => {
  if (!saved.value) return false
  const p = voice.preferences
  return p.persona_slug !== saved.value.persona_slug
    || p.speak_enabled !== saved.value.speak_enabled
    || p.browser_fallback !== saved.value.browser_fallback
})

const saveState = computed(() => {
  if (saveResult.value?.error) return { error: true, text: `${saveResult.value.error} Your choice still applies on this device.` }
  if (dirty.value) return { error: false, text: 'Unsaved changes.' }
  if (saveResult.value?.success) return { error: false, text: 'Saved.' }
  return { error: false, text: '' }
})

const HIDDEN_TAGS = new Set(['elevenlabs', 'fishaudio', 'intron', 'piper', 'azure'])

function visibleTags(p) {
  return (p.tags || []).filter((t) => !HIDDEN_TAGS.has(t)).slice(0, 4)
}

function recommendedForAtlas(p) {
  return (p.recommended_for || []).includes('atlas')
}

function costLabel(p) {
  const n = Number(p.cost_per_1k_chars)
  if (p.cost_per_1k_chars == null || !Number.isFinite(n)) return 'Cost unverified'
  if (n === 0) return 'Free (self-hosted)'
  return `$${n.toFixed(n < 0.1 ? 3 : 2)} per 1k characters`
}

function previewing(slug) {
  return voice.previewingSlug === slug
}

function onPreview(slug) {
  if (previewing(slug)) {
    voice.stop()
    return
  }
  voice.preview(slug)
}

function onToggleSpeak() {
  const next = !voice.speakEnabled
  voice.speakEnabled = next
  // Unlock inside the click so the first auto-read isn't blocked.
  if (next) voice.unlockAudio()
  else voice.stop()
}

async function onSave() {
  saveResult.value = null
  const res = await voice.savePreferences()
  saveResult.value = res
  if (res.success) saved.value = { ...voice.preferences }
}

function switchStyle(on) {
  return {
    position: 'relative',
    width: '38px',
    height: '22px',
    borderRadius: '999px',
    border: '1px solid var(--border)',
    background: on ? 'var(--status-live)' : 'var(--bg-elevated)',
    transition: 'background 0.15s ease',
  }
}

function knobStyle(on) {
  return {
    position: 'absolute',
    top: '2px',
    left: on ? '18px' : '2px',
    width: '16px',
    height: '16px',
    borderRadius: '999px',
    background: on ? 'var(--bg-card)' : 'var(--text-muted)',
    transition: 'left 0.15s ease',
  }
}

onMounted(async () => {
  await Promise.all([voice.fetchVoices(), voice.fetchPreferences()])
  voice.ensureSelection()
  saved.value = { ...voice.preferences }
})

onBeforeUnmount(() => {
  voice.stop()
})
</script>
