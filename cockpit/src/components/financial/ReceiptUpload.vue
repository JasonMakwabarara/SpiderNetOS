<template>
  <div>
    <div
      class="rounded-lg border border-dashed px-4 py-4 text-center cursor-pointer transition-colors"
      :style="dragging
        ? 'border-color: var(--accent); background: var(--accent-weak);'
        : 'border-color: var(--border); background: var(--bg-elevated);'"
      role="button"
      tabindex="0"
      aria-label="Upload receipt"
      data-testid="receipt-dropzone"
      @click="inputRef?.click()"
      @keydown.enter.prevent="inputRef?.click()"
      @dragover.prevent="dragging = true"
      @dragleave.prevent="dragging = false"
      @drop.prevent="onDrop"
    >
      <svg class="w-5 h-5 mx-auto mb-1.5" style="color: var(--text-muted);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L8 8m4-4l4 4M4 20h16"/>
      </svg>
      <p class="text-xs" style="color: var(--text-secondary);">
        Drop a receipt here or <span style="color: var(--accent);">browse</span>
      </p>
      <p class="text-[10px] mt-0.5" style="color: var(--text-muted);">
        JPEG, PNG, WebP or PDF · max 10 MB
      </p>
      <input
        ref="inputRef"
        type="file"
        class="hidden"
        accept="image/jpeg,image/png,image/webp,application/pdf"
        data-testid="receipt-file-input"
        @change="onPick"
      />
    </div>

    <p v-if="validationError" class="text-xs mt-1.5" style="color: var(--danger);" data-testid="receipt-error">
      {{ validationError }}
    </p>

    <!-- Upload progress -->
    <div v-if="progress > 0 && progress < 100" class="mt-2" data-testid="receipt-progress">
      <div class="h-1.5 rounded-full overflow-hidden" style="background: var(--bg-elevated);">
        <div
          class="h-full rounded-full transition-all"
          :style="`width: ${progress}%; background: var(--accent);`"
        ></div>
      </div>
      <p class="text-[10px] mt-1 mono" style="color: var(--text-muted);">{{ progress }}%</p>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'

/**
 * ReceiptUpload — drag-drop / picker for expense receipts.
 *
 * Client-side validation: mime whitelist (jpeg/png/webp/pdf) + <= 10 MB.
 * Emits 'upload' with the raw File; the parent owns the API call and
 * feeds `progress` back in (0-100).
 */
const props = defineProps({
  progress: { type: Number, default: 0 },
})

const emit = defineEmits(['upload'])

const MAX_BYTES = 10 * 1024 * 1024
const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']

const inputRef = ref(null)
const dragging = ref(false)
const validationError = ref('')

function validate(file) {
  if (!file) return 'No file selected.'
  if (!ALLOWED_MIMES.includes(file.type)) {
    return 'Unsupported file type. Use JPEG, PNG, WebP, or PDF.'
  }
  if (file.size > MAX_BYTES) {
    return 'File is larger than 10 MB.'
  }
  return ''
}

function handleFile(file) {
  validationError.value = validate(file)
  if (validationError.value) return
  emit('upload', file)
}

function onPick(e) {
  const file = e.target?.files?.[0]
  handleFile(file)
  if (inputRef.value) inputRef.value.value = ''
}

function onDrop(e) {
  dragging.value = false
  const file = e.dataTransfer?.files?.[0]
  handleFile(file)
}
</script>
