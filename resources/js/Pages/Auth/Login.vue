<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Connexion" />

        <h1 class="text-[17px] leading-tight text-sand-900" style="font-variation-settings: 'wght' 600">
            Connexion
        </h1>
        <p class="mt-1 text-[13px] text-sand-600">
            Accès au module métré. Vous arrivez normalement ici depuis ShakeDesign.
        </p>

        <div v-if="status" class="banner mt-4 border-success-200 bg-success-50 text-success-700">
            {{ status }}
        </div>

        <form class="mt-5 space-y-4" @submit.prevent="submit">
            <div>
                <InputLabel for="email" value="Adresse e-mail" />

                <TextInput
                    id="email"
                    type="email"
                    class="block w-full"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                />

                <InputError class="mt-1.5" :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="password" value="Mot de passe" />

                <TextInput
                    id="password"
                    type="password"
                    class="block w-full"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                />

                <InputError class="mt-1.5" :message="form.errors.password" />
            </div>

            <label class="flex items-center gap-2 text-[13px] text-sand-700">
                <Checkbox name="remember" v-model:checked="form.remember" />
                Se souvenir de moi
            </label>

            <div class="flex items-center justify-between gap-3 border-t border-sand-200 pt-4">
                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="rounded text-[13px] text-sand-600 underline decoration-sand-300 underline-offset-2 hover:text-sand-900"
                >
                    Mot de passe oublié ?
                </Link>
                <span v-else />

                <PrimaryButton :disabled="form.processing">Se connecter</PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
