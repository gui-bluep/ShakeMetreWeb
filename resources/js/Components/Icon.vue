<script setup>
/**
 * Le jeu d'icônes de l'application.
 *
 * Un composant et une table de tracés plutôt qu'une dépendance : il en faut une vingtaine,
 * toutes dessinées sur la même grille de 24 et le même trait de 1,75, et c'est cette
 * régularité qui fait qu'elles se lisent comme une famille. Une bibliothèque importée aurait
 * apporté quelques centaines d'icônes dont deux styles différents.
 *
 * `v-html` porte ici une table constante définie dans ce fichier — aucune donnée extérieure
 * n'y entre jamais.
 */
const props = defineProps({
    name: { type: String, required: true },
    /** Taille en unités Tailwind (`size-4` = 16 px). 4 par défaut : la taille de barre d'outils. */
    size: { type: [String, Number], default: 4 },
});

/**
 * Les classes sont écrites en clair : Tailwind lit les fichiers sources comme du texte, donc
 * une classe assemblée en `size-${size}` ne serait jamais générée.
 */
const SIZES = {
    3: 'size-3',
    3.5: 'size-3.5',
    4: 'size-4',
    5: 'size-5',
    6: 'size-6',
    8: 'size-8',
};

const ICONS = {
    search: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6"/>',
    'chevron-right': '<path d="m9.5 6 6 6-6 6"/>',
    'chevron-down': '<path d="m6 9.5 6 6 6-6"/>',
    'chevron-left': '<path d="m14.5 6-6 6 6 6"/>',
    'arrow-left': '<path d="M19 12H5"/><path d="m11 18-6-6 6-6"/>',
    home: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    check: '<path d="m5 12.5 4.5 4.5L19 7"/>',
    x: '<path d="M6 6l12 12M18 6 6 18"/>',
    ellipsis:
        '<circle cx="5.5" cy="12" r="1.5" fill="currentColor" stroke="none"/>'
        + '<circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none"/>'
        + '<circle cx="18.5" cy="12" r="1.5" fill="currentColor" stroke="none"/>',
    pencil: '<path d="m14.5 5.5 4 4"/><path d="M4 20l.7-4.2L15.9 4.6a2.1 2.1 0 0 1 3 3L7.7 19.3 4 20Z"/>',
    trash: '<path d="M4 7h16"/><path d="M9.5 7V4.5h5V7"/><path d="M6.5 7 7.5 20h9l1-13"/>',
    copy: '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M15 5.5H6.5a2 2 0 0 0-2 2V16"/>',
    document:
        '<path d="M14 3.5H7.5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2V8Z"/>'
        + '<path d="M14 3.5V8h4.5"/><path d="M9 13h6M9 16.5h4"/>',
    lock: '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8.5 11V8a3.5 3.5 0 0 1 7 0v3"/>',
    alert:
        '<path d="M12 4 2.8 20h18.4L12 4Z"/><path d="M12 10v4"/>'
        + '<circle cx="12" cy="17" r="1" fill="currentColor" stroke="none"/>',
    layers: '<path d="m12 3 9 4.8-9 4.8-9-4.8L12 3Z"/><path d="m3 13.2 9 4.8 9-4.8"/>',
    table: '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><path d="M3.5 9.5h17M9.5 9.5V19.5"/>',
    project:
        '<rect x="3.5" y="7" width="17" height="12.5" rx="2"/>'
        + '<path d="M9 7V5.5A1.5 1.5 0 0 1 10.5 4h3A1.5 1.5 0 0 1 15 5.5V7"/>',
    user: '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.6 3.1-5.8 7-5.8s7 2.2 7 5.8"/>',
    logout: '<path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4"/><path d="M4 12h10"/><path d="m8 8-4 4 4 4"/>',
    external: '<path d="M14 4h6v6"/><path d="M20 4l-8.5 8.5"/><path d="M18 14.5V18a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h3.5"/>',
};
</script>

<template>
    <svg
        :class="[SIZES[props.size] ?? SIZES[4], 'shrink-0']"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.75"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        v-html="ICONS[name] ?? ''"
    />
</template>
