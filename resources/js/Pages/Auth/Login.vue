<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AuthenticationCard from '@/Components/AuthenticationCard.vue';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import { Field, FieldLabel, FieldError } from '@/packages/ui/src/field';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import TextInput from '@/packages/ui/src/Input/TextInput.vue';

const props = defineProps<{
    canResetPassword?: boolean;
    canRegister?: boolean;
    passwordLoginEnabled?: boolean;
    status?: string;
    oidc?: {
        enabled: boolean;
        label: string;
        url: string | null;
    };
}>();

const form = useForm({
    email: '',
    password: '',
    remember: true,
});

const submit = () => {
    form.transform((data) => ({
        ...data,
        remember: form.remember ? 'on' : '',
    })).post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};

const page = usePage<{
    flash: {
        message: string;
    };
}>();

const unframedSsoOnly = computed(
    () =>
        !props.passwordLoginEnabled &&
        props.oidc?.enabled === true &&
        typeof props.oidc.url === 'string' &&
        props.oidc.url !== ''
);
</script>

<template>
    <Head title="Log in" />

    <AuthenticationCard :unframed="unframedSsoOnly">
        <template #logo>
            <AuthenticationCardLogo />
        </template>

        <template #actions>
            <Link
                v-if="canRegister"
                class="py-8 text-text-secondary text-sm font-medium opacity-90 hover:opacity-100 transition"
                :href="route('register')">
                No account yet? <span class="text-text-primary">Register here!</span>
            </Link>
        </template>

        <div v-if="status" class="mb-4 font-medium text-sm text-green-400">
            {{ status }}
        </div>
        <div
            v-if="page.props.flash?.message"
            class="bg-red-400 text-black text-center w-full px-3 py-1 mb-4 rounded-lg">
            {{ page.props.flash?.message }}
        </div>

        <div v-if="oidc?.enabled && oidc.url" class="mb-6">
            <a
                :href="oidc.url"
                class="flex w-full items-center justify-center rounded-md border border-border bg-card-background px-4 py-2.5 text-sm font-medium text-text-primary transition hover:border-text-secondary hover:bg-card-background-separator focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                {{ oidc.label }}
            </a>

            <div
                v-if="passwordLoginEnabled"
                class="mt-6 flex items-center gap-3 text-xs uppercase tracking-wide text-text-secondary">
                <div class="h-px flex-1 bg-card-background-separator"></div>
                <span>or</span>
                <div class="h-px flex-1 bg-card-background-separator"></div>
            </div>
        </div>

        <form v-if="passwordLoginEnabled" @submit.prevent="submit">
            <Field>
                <FieldLabel for="email">Email</FieldLabel>
                <TextInput
                    id="email"
                    v-model="form.email"
                    type="email"
                    class="block w-full"
                    required
                    autofocus
                    autocomplete="username" />
                <FieldError v-if="form.errors.email">{{ form.errors.email }}</FieldError>
            </Field>

            <Field class="mt-4">
                <FieldLabel for="password">Password</FieldLabel>
                <TextInput
                    id="password"
                    v-model="form.password"
                    type="password"
                    class="block w-full"
                    required
                    autocomplete="current-password" />
                <FieldError v-if="form.errors.password">{{ form.errors.password }}</FieldError>
            </Field>

            <div class="flex items-center justify-end mt-4">
                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="underline text-sm text-text-secondary hover:text-text-primary rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                    Forgot your password?
                </Link>

                <PrimaryButton
                    class="ms-4"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing">
                    Log in
                </PrimaryButton>
            </div>
        </form>
    </AuthenticationCard>
</template>
