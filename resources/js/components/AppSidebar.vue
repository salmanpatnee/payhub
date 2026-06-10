<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Building2, CreditCard, LayoutDashboard, Settings, UserCheck2, Users, Wallet } from 'lucide-vue-next';
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
const roles = computed(() => page.props.auth.user?.roles ?? []);
const isAdmin = computed(() => roles.value.includes('admin'));
const isAccount = computed(() => roles.value.includes('account'));
const canViewDashboard = computed(() => isAdmin.value || isAccount.value);

const mainNavItems = computed((): NavItem[] => [
    ...(canViewDashboard.value ? [
        { title: 'Dashboard', href: '/dashboard', icon: LayoutDashboard } as NavItem,
    ] : []),
    ...(isAdmin.value ? [
        { title: 'Brands',          href: '/admin/brands',                  icon: Building2  } as NavItem,
        { title: 'Stripe Accounts', href: '/admin/stripe-accounts',         icon: Wallet     } as NavItem,
        { title: 'Users',           href: '/admin/users',                   icon: Users      } as NavItem,
        { title: 'RMs',             href: '/admin/relationship-managers',   icon: UserCheck2 } as NavItem,
    ] : []),
    { title: 'Payments', href: '/payments', icon: CreditCard },
    ...(isAdmin.value ? [
        { title: 'Settings', href: '/settings/profile', icon: Settings } as NavItem,
    ] : []),
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
