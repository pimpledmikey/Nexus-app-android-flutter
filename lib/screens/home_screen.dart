import 'package:flutter/material.dart';
import 'package:animated_notch_bottom_bar/animated_notch_bottom_bar/animated_notch_bottom_bar.dart';
import 'registro_remoto_screen.dart';
import 'historial_screen.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  final NotchBottomBarController _controller = NotchBottomBarController(index: 0);
  final PageController _pageController = PageController(initialPage: 0);

  @override
  void dispose() {
    _pageController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    
    return Scaffold(
      backgroundColor: Colors.transparent,
      extendBody: true,
      extendBodyBehindAppBar: true,
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [
              Color(0xFFe0eafc),
              Color(0xFFcfdef3),
              Color(0xFFf9fafc),
            ],
          ),
        ),
        child: PageView(
          controller: _pageController,
          physics: const NeverScrollableScrollPhysics(),
          children: const [
            RegistroRemotoScreen(),
            HistorialScreen(),
          ],
        ),
      ),
      bottomNavigationBar: SafeArea(
        bottom: true,
        top: false,
        left: false,
        right: false,
        child: AnimatedNotchBottomBar(
        notchBottomBarController: _controller,
        color: Colors.white,
        showLabel: true,
        textOverflow: TextOverflow.ellipsis,
        maxLine: 1,
        shadowElevation: 8,
        kBottomRadius: 20.0,
        kIconSize: 22.0,
        notchColor: theme.colorScheme.primary,
        removeMargins: false,
        bottomBarWidth: 280,
        showShadow: true,
        durationInMilliSeconds: 250,
        itemLabelStyle: TextStyle(
          fontSize: 11,
          fontWeight: FontWeight.w500,
          color: theme.colorScheme.primary,
        ),
        elevation: 2,
        bottomBarItems: [
          BottomBarItem(
            inActiveItem: Icon(
              Icons.touch_app_rounded,
              color: Colors.grey[500],
            ),
            activeItem: const Icon(
              Icons.touch_app_rounded,
              color: Colors.white,
            ),
            itemLabel: 'Registro',
          ),
          BottomBarItem(
            inActiveItem: Icon(
              Icons.history_rounded,
              color: Colors.grey[500],
            ),
            activeItem: const Icon(
              Icons.history_rounded,
              color: Colors.white,
            ),
            itemLabel: 'Historial',
          ),
        ],
        onTap: (index) {
          _pageController.jumpToPage(index);
        },
      ),
      ),
    );
  }
}
