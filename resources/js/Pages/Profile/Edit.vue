<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});
</script>

<template>
    <Head title="Profil" />

    <AuthenticatedLayout title="Profil" width="narrow" :breadcrumbs="[{ label: 'Profil' }]">
        <div class="space-y-4">
            <div class="surface p-5">
                <UpdateProfileInformationForm :must-verify-email="mustVerifyEmail" :status="status" />
            </div>

            <div class="surface p-5">
                <UpdatePasswordForm />
            </div>

            <div class="surface p-5">
                <DeleteUserForm />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
