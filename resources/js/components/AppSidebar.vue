<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Building2, CreditCard, Settings, Users, Wallet } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import type { NavItem } from '@/types';

const page = usePage();
const isAdmin = computed(() =>
    page.props.auth.user?.roles?.includes('admin') ?? false
);

const mainNavItems = computed((): NavItem[] => [
    { title: 'Brands',          href: '/admin/brands',            icon: Building2 },
    { title: 'Stripe Accounts', href: '/admin/stripe-accounts',   icon: Wallet },
    { title: 'Payments',        href: '/payments',                icon: CreditCard },
    ...(isAdmin.value ? [{ title: 'Users', href: '/admin/users', icon: Users } as NavItem] : []),
    { title: 'Settings',        href: '/settings/profile',        icon: Settings },
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <a href="/payments" class="flex items-center px-2 py-1">
                        <AppLogo />
                    </a>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
