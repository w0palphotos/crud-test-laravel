<script setup>
import { ref } from 'vue';

const props = defineProps({
    node: { type: Object, required: true },
    selectedPath: { type: String, default: null },
    depth: { type: Number, default: 0 },
});

const emit = defineEmits(['select']);

const open = ref(props.depth === 0);

function toggle() {
    if (props.node.type === 'folder') {
        open.value = !open.value;
    }
}

function select() {
    if (props.node.type === 'file') {
        emit('select', props.node.path);
    }
}
</script>

<template>
    <div>
        <button
            type="button"
            class="flex w-full items-center gap-1.5 rounded px-1 py-0.5 text-left text-sm hover:bg-neutral-100"
            :style="{ paddingLeft: `${depth * 14 + 4}px` }"
            @click="node.type === 'folder' ? toggle() : select()"
        >
            <span class="text-neutral-400">
                <template v-if="node.type === 'folder'">
                    {{ open ? '▾' : '▸' }}
                </template>
                <template v-else>·</template>
            </span>
            <span
                :class="[
                    node.type === 'folder' ? 'font-medium text-neutral-700' : 'text-neutral-600',
                    node.type === 'file' && selectedPath === node.path ? 'text-admin-primary font-medium' : '',
                ]"
            >
                {{ node.name }}
            </span>
            <span v-if="node.type === 'file'" class="ml-auto text-xs text-neutral-400">
                {{ (node.size / 1024).toFixed(1) }} KB
            </span>
        </button>

        <div v-if="node.type === 'folder' && open">
            <LogTreeNode
                v-for="child in node.children"
                :key="child.path"
                :node="child"
                :selected-path="selectedPath"
                :depth="depth + 1"
                @select="emit('select', $event)"
            />
        </div>
    </div>
</template>

<script>
export default { name: 'LogTreeNode' };
</script>