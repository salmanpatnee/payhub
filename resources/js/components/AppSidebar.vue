<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Banknote, Building2, CreditCard, Landmark, LayoutDashboard, Settings, UserCheck2, Users, Wallet } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import CloverIcon from '@/components/icons/CloverIcon.vue';
import ZelleIcon from '@/components/icons/ZelleIcon.vue';
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
    { title: 'Payments', href: '/payments', icon: CreditCard },
    ...(isAdmin.value ? [
        { title: 'Brands', href: '/admin/brands', icon: Building2 } as NavItem,
        {
            title: 'Payment Gateways',
            href: '/admin/stripe-accounts',
            icon: CreditCard,
            children: [
                { title: 'Stripe Accounts',  href: '/admin/stripe-accounts',  icon: Wallet },
                { title: 'Clover Accounts',  href: '/admin/clover-accounts',  icon: CloverIcon },
                { title: 'Revolut Accounts', href: '/admin/revolut-accounts', icon: Landmark },
                { title: 'Square Accounts',  href: '/admin/square-accounts',  icon: Wallet },
                { title: 'Viva Accounts',    href: '/admin/viva-accounts',    icon: Landmark },
            ],
        } as NavItem,
    ] : []),
    {
        title: 'Banks/Wallets',
        href: '/bank-accounts',
        icon: Banknote,
        children: [
            { title: 'Bank Accounts',  href: '/bank-accounts',  icon: Banknote },
            { title: 'Zelle Accounts', href: '/zelle-accounts', icon: ZelleIcon },
        ],
    },
    ...(isAdmin.value ? [
        {
            title: 'People',
            href: '/admin/users',
            icon: Users,
            children: [
                { title: 'Users', href: '/admin/users',                 icon: Users },
                { title: 'RMs',   href: '/admin/relationship-managers', icon: UserCheck2 },
            ],
        } as NavItem,
    ] : []),
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
