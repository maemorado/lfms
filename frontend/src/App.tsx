import { Navigate, Route, Routes, Link } from "react-router-dom";
import LoginPage from "@/pages/LoginPage";
import DashboardPage from "@/pages/DashboardPage";
import UserManagementPage from "@/pages/UserManagementPage";
import ProfilePage from "@/pages/ProfilePage";
import InventoryPage from "@/pages/InventoryPage";
import InviteAcceptancePage from "@/pages/InviteAcceptancePage";
import { ProtectedRoute } from "@/routes/ProtectedRoute";
import { PublicOnlyRoute } from "@/routes/PublicOnlyRoute";
function NotFoundPage(){return <main className="flex min-h-screen items-center justify-center bg-slate-50 px-6"><div className="max-w-md text-center"><p className="text-sm font-semibold tracking-[0.2em] text-teal-700">LFMS</p><h1 className="mt-4 text-3xl font-semibold text-slate-950">Page not found</h1><p className="mt-3 text-sm leading-6 text-slate-600">The page you requested does not exist.</p><Link to="/dashboard" className="mt-6 inline-flex min-h-11 items-center rounded-xl bg-slate-900 px-5 text-sm font-semibold text-white hover:bg-slate-700">Return to workspace</Link></div></main>}
export default function App(){return <Routes><Route path="/auth/accept-invite" element={<InviteAcceptancePage/>}/><Route path="/login" element={<PublicOnlyRoute><LoginPage/></PublicOnlyRoute>}/><Route element={<ProtectedRoute/>}><Route path="/" element={<Navigate to="/dashboard" replace/>}/><Route path="/dashboard" element={<DashboardPage/>}/><Route path="/users" element={<UserManagementPage/>}/><Route path="/profile" element={<ProfilePage/>}/><Route path="/inventory" element={<InventoryPage/>}/></Route><Route path="*" element={<NotFoundPage/>}/></Routes>}
